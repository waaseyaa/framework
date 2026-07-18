<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Audit\Exception\PrivilegedReadLedgerException;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Database\DatabaseInterface;

/** Durable strict ledger implemented as immutable reservation/finalization events. @api */
final readonly class DatabaseStrictPrivilegedReadLedger implements StrictPrivilegedReadLedgerInterface
{
    private DatabaseInterface $database;

    public function __construct(DatabaseInterface $database)
    {
        $this->database = $database instanceof AppendOnlyAuditDatabase
            ? $database
            : new AppendOnlyAuditDatabase($database);
    }

    public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
    {
        $receipt = new PrivilegedReadReceipt(bin2hex(random_bytes(16)));
        try {
            $this->append($receipt, 'reserved', null, $this->encode($descriptor));
        } catch (\Throwable $e) {
            throw new PrivilegedReadLedgerException('Strict privileged-read reservation could not be made durable.', 0, $e);
        }
        return $receipt;
    }

    public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
    {
        $transaction = $this->database->transaction('privileged-read-finalize');
        try {
            $events = iterator_to_array($this->database->query(
                'SELECT event_type FROM privileged_read_ledger WHERE receipt_id = :receipt ORDER BY id',
                ['receipt' => $receipt->id],
            ));
            if (count($events) !== 1 || ($events[0]['event_type'] ?? null) !== 'reserved') {
                throw new \LogicException('Only a durable, unfinished privileged-read reservation may be finalized.');
            }
            $this->append($receipt, 'finalized', $outcome->value, null);
            $transaction->commit();
        } catch (\Throwable $e) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }
            if ($e instanceof \LogicException) {
                throw $e;
            }
            throw new PrivilegedReadLedgerException('Strict privileged-read outcome could not be made durable.', 0, $e);
        }
    }

    private function append(PrivilegedReadReceipt $receipt, string $eventType, ?string $outcome, ?string $descriptor): void
    {
        $this->database->insert('privileged_read_ledger')->values([
            'receipt_id' => $receipt->id,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'descriptor' => $descriptor,
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s.u'),
        ])->execute();
    }

    private function encode(PrivilegedReadDescriptor $descriptor): string
    {
        return json_encode([
            'kind' => $descriptor->kind->value,
            'reason' => $descriptor->reason->value,
            'issuer' => $descriptor->issuer,
            'actor_semantics' => $descriptor->actorSemantics->value,
            'actor_id' => $descriptor->actorId,
            'entity_type_id' => $descriptor->entityTypeId,
            'entity_id' => $descriptor->entityId,
            'fields' => $descriptor->fields,
            'bundles' => $descriptor->bundles,
            'tenant_id' => $descriptor->tenantId,
            'community_id' => $descriptor->communityId,
            'query_fingerprint' => $descriptor->queryFingerprint,
            'query_operations' => array_map(static fn($operation): string => $operation->value, $descriptor->queryOperations),
            'classification_generation' => $descriptor->classificationGeneration,
            'policy_generation' => $descriptor->policyGeneration,
            'correlation_id' => $descriptor->correlationId,
            'call_site' => $descriptor->callSite,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
