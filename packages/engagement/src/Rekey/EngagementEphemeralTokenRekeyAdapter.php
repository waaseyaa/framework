<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Engagement\EngagementTokens;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification as RekeyVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/** Zero-row inventory for short-lived engagement tokens. */
final readonly class EngagementEphemeralTokenRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'engagement-ephemeral-token-v1';
    private const array PURPOSES = [EngagementTokens::SUBMISSION_PURPOSE, EngagementTokens::CURSOR_PURPOSE];

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
        return self::PURPOSES;
    }
    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->snapshotFor($context, 'forward');
    }
    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->snapshotFor($context, 'rollback');
    }
    public function transitionBatch(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot, ?string $cursor, int $limit): ApplicationMasterBatchResult
    {
        throw new ApplicationMasterRekeyConflictException('Engagement tokens are ephemeral and have no transition batches.');
    }
    public function rollbackBatch(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot, ?string $cursor, int $limit): ApplicationMasterBatchResult
    {
        throw new ApplicationMasterRekeyConflictException('Engagement token rollback is ephemeral and has no transition batches.');
    }
    public function verify(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyFor($context, $snapshot, 'forward');
    }
    public function verifyRollback(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyFor($context, $snapshot, 'rollback');
    }

    private function snapshotFor(ApplicationMasterRekeyContext $context, string $direction): ApplicationMasterInventorySnapshot
    {
        $this->assertBoundary($context, $direction);
        return new ApplicationMasterInventorySnapshot($this->token($context, $direction), 0);
    }

    private function verifyFor(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot, string $direction): array
    {
        $this->assertBoundary($context, $direction);
        if ($snapshot->totalRecords !== 0 || !hash_equals($snapshot->token, $this->token($context, $direction))) {
            throw new ApplicationMasterRekeyConflictException('Engagement token inventory snapshot is not exact.');
        }
        $result = [];
        foreach (self::PURPOSES as $purpose) {
            $result[$purpose] = new RekeyVerification(0, hash('sha256', implode("\0", [
                'waaseyaa.engagement.ephemeral-token-verification.v1', $context->request->requestDigest, $direction, $snapshot->token, $purpose,
            ])));
        }
        return $result;
    }

    private function assertBoundary(ApplicationMasterRekeyContext $context, string $direction): void
    {
        $expected = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($context->database !== $this->database || $context->keyring->activeVersion() !== $expected) {
            throw new ApplicationMasterRekeyConflictException('Engagement token rekey requires the exact database and active key version.');
        }
    }

    private function token(ApplicationMasterRekeyContext $context, string $direction): string
    {
        return hash('sha256', implode("\0", ['waaseyaa.engagement.ephemeral-token-snapshot.v1', $context->request->requestDigest, $direction, 'records:0']));
    }
}
