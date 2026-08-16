<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/** Invalidates every persisted cache bin through one bounded generation CAS. @api */
final readonly class CacheGenerationRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    private const string ADAPTER_ID = 'cache-generation-v1';
    private const string PURPOSE = ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC;

    public function __construct(private DatabaseInterface $database) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return self::ADAPTER_ID;
    }

    public function purposeIds(): array
    {
        return [self::PURPOSE];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $generation = $this->generation($context);

        return new ApplicationMasterInventorySnapshot(
            $this->snapshotToken($context, 'forward', $generation),
            1,
        );
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        return $this->advance($context, $snapshot, $cursor, $limit, 'forward');
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return $this->verification($context, $snapshot, 'forward');
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        return $this->advance($context, $snapshot, $cursor, $limit, 'rollback');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $generation = $this->generation($context);

        return new ApplicationMasterInventorySnapshot(
            $this->snapshotToken($context, 'rollback', $generation),
            1,
        );
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return $this->verification($context, $snapshot, 'rollback');
    }

    private function advance(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
        string $direction,
    ): ApplicationMasterBatchResult {
        if ($cursor !== null || $limit < 1 || $snapshot->totalRecords !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation transition requires one unprocessed logical record.',
            );
        }
        $current = $this->generation($context);
        if ($snapshot->token !== $this->snapshotToken($context, $direction, $current)) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation changed after its immutable snapshot.',
            );
        }
        $next = $current + 1;
        $updated = $context->database->update('cache_generation')->fields([
            'generation' => $next,
        ])->condition('singleton_id', 1)
            ->condition('generation', $current)
            ->execute();
        if ($updated !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation changed during its compare-and-swap transition.',
            );
        }

        return new ApplicationMasterBatchResult(
            nextCursor: 'generation:' . $next,
            transitionedRecords: 1,
            purposeCountDeltas: [self::PURPOSE => 1],
            batchCommitment: hash('sha256', json_encode([
                'format' => 'waaseyaa.cache.generation-batch.v1',
                'request_digest' => $context->request->requestDigest,
                'direction' => $direction,
                'from_generation' => $current,
                'to_generation' => $next,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verification(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $generation = $this->generation($context);
        $expectedPredecessor = $generation > 1
            ? $this->snapshotToken($context, $direction, $generation - 1)
            : '';
        if ($snapshot->totalRecords !== 1 || !hash_equals($snapshot->token, $expectedPredecessor)) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation is not the exact successor of its immutable snapshot.',
            );
        }

        return [self::PURPOSE => new ApplicationMasterPurposeVerification(
            1,
            hash('sha256', json_encode([
                'format' => 'waaseyaa.cache.generation-verification.v1',
                'request_digest' => $context->request->requestDigest,
                'direction' => $direction,
                'snapshot_token' => $snapshot->token,
                'active_generation' => $generation,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        )];
    }

    private function generation(ApplicationMasterRekeyContext $context): int
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache adapter requires its exact database transaction authority.',
            );
        }
        $rows = iterator_to_array($context->database->query(
            'SELECT generation FROM cache_generation WHERE singleton_id = 1',
        ));
        if (count($rows) !== 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation schema is unavailable or malformed.',
            );
        }
        $value = $rows[0]['generation'] ?? null;
        if ((!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1))
            || (int) $value < 1) {
            throw new ApplicationMasterRekeyConflictException(
                'The cache generation value is malformed.',
            );
        }

        return (int) $value;
    }

    private function snapshotToken(
        ApplicationMasterRekeyContext $context,
        string $direction,
        int $generation,
    ): string {
        return hash('sha256', json_encode([
            'format' => 'waaseyaa.cache.generation-snapshot.v1',
            'request_digest' => $context->request->requestDigest,
            'direction' => $direction,
            'generation' => $generation,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
