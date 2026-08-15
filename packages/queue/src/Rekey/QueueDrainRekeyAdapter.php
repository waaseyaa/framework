<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/** Refuses predecessor revocation until persistent and failed queue payloads drain. @api */
final readonly class QueueDrainRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'queue-drain-v1';

    private const array TABLES = ['waaseyaa_queue_jobs', 'waaseyaa_failed_jobs'];
    private const string LEGACY_PREFIX = 'hmac-sha256.hkdf-v1:';
    private const string APPLICATION_MASTER_PREFIX = 'hmac-sha256.application-master.v1:';

    public function __construct(private DatabaseInterface $database) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function purposeIds(): array
    {
        return [ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        $remaining = $this->matchingCount($context->request->fromVersion, includeLegacy: true);
        if ($remaining !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'Predecessor queue payloads must be drained or expired before the application-master snapshot.',
            );
        }

        return new ApplicationMasterInventorySnapshot($this->snapshotToken($context, 'forward'), 0);
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'Queue drain transitions have no mutation batches; drain before snapshot and complete the zero-row adapter.',
        );
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        $this->assertAuthority($context);
        $this->assertSnapshot($snapshot, $this->snapshotToken($context, 'forward'));
        if ($this->matchingCount($context->request->fromVersion, includeLegacy: true) !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'A predecessor queue payload appeared after the drain snapshot.',
            );
        }

        return $this->verification($context, 'forward');
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'Queue rollback drains have no mutation batches; drain before snapshot and complete the zero-row adapter.',
        );
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        if ($this->matchingCount($context->request->toVersion, includeLegacy: false) !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'Queue payloads written by the failed successor must be drained or expired before rollback.',
            );
        }

        return new ApplicationMasterInventorySnapshot($this->snapshotToken($context, 'rollback'), 0);
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        $this->assertAuthority($context);
        $this->assertSnapshot($snapshot, $this->snapshotToken($context, 'rollback'));
        if ($this->matchingCount($context->request->toVersion, includeLegacy: false) !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'A failed-successor queue payload appeared after the rollback drain snapshot.',
            );
        }

        return $this->verification($context, 'rollback');
    }

    private function assertAuthority(ApplicationMasterRekeyContext $context): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'Queue rekey operations require the exact coordinator database authority.',
            );
        }
    }

    private function assertSnapshot(ApplicationMasterInventorySnapshot $snapshot, string $expectedToken): void
    {
        if ($snapshot->totalRecords !== 0 || !hash_equals($expectedToken, $snapshot->token)) {
            throw new ApplicationMasterRekeyConflictException('The queue drain snapshot does not match this request.');
        }
    }

    private function matchingCount(int $masterVersion, bool $includeLegacy): int
    {
        $masterPattern = self::APPLICATION_MASTER_PREFIX . $masterVersion . ':%';
        $total = 0;
        foreach (self::TABLES as $table) {
            $query = $this->database->select($table, 'queue_rekey');
            if ($includeLegacy) {
                $query = $query->whereRaw('(payload LIKE ? OR payload LIKE ?)', [self::LEGACY_PREFIX . '%', $masterPattern]);
            } else {
                $query = $query->condition('payload', $masterPattern, 'LIKE');
            }
            foreach ($query->countQuery()->execute() as $row) {
                $total += (int) reset($row);
                break;
            }
        }

        return $total;
    }

    private function snapshotToken(ApplicationMasterRekeyContext $context, string $direction): string
    {
        return hash('sha256', implode("\0", [
            'waaseyaa.queue.application-master-drain.snapshot.v1',
            $context->request->requestDigest,
            $direction,
            (string) $context->request->fromVersion,
            (string) $context->request->toVersion,
            'remaining:0',
        ]));
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verification(ApplicationMasterRekeyContext $context, string $direction): array
    {
        return [ApplicationSecret::PURPOSE_QUEUE_PAYLOAD_HMAC => new ApplicationMasterPurposeVerification(
            0,
            hash('sha256', implode("\0", [
                'waaseyaa.queue.application-master-drain.verification.v1',
                $context->request->requestDigest,
                $direction,
                'remaining:0',
            ])),
        )];
    }
}
