<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\SensitiveKey;
use Waaseyaa\Oidc\Security\OpaqueTokenProtector;

/** Shared joint-row CAS mechanics for OIDC opaque-token purpose owners. @internal */
abstract class AbstractOidcTokenRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    private readonly ?SensitiveKey $legacyEncryptionKey;
    private readonly ?SensitiveKey $legacyLookupKey;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $legacyEncryptionKey,
        #[\SensitiveParameter]
        ?string $legacyLookupKey,
        private readonly string $adapterId,
        private readonly string $table,
        private readonly string $encryptionPurpose,
        private readonly string $lookupPurpose,
    ) {
        if (($legacyEncryptionKey === null) !== ($legacyLookupKey === null)) {
            throw new \InvalidArgumentException('OIDC token legacy custody requires both purpose keys or neither.');
        }
        if ($legacyEncryptionKey !== null && strlen($legacyEncryptionKey) !== 32) {
            throw new \InvalidArgumentException('OIDC token legacy encryption keys must be 32 bytes.');
        }
        if ($legacyLookupKey !== null && strlen($legacyLookupKey) !== 32) {
            throw new \InvalidArgumentException('OIDC token legacy lookup keys must be 32 bytes.');
        }
        $this->legacyEncryptionKey = $legacyEncryptionKey === null ? null : new SensitiveKey($legacyEncryptionKey);
        $this->legacyLookupKey = $legacyLookupKey === null ? null : new SensitiveKey($legacyLookupKey);
    }

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return $this->adapterId;
    }

    public function purposeIds(): array
    {
        return [$this->encryptionPurpose, $this->lookupPurpose];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        $this->assertActiveVersion($context, 'forward');

        return $this->captureSnapshot($context, 'forward');
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        return $this->transition($context, $snapshot, $cursor, $limit, 'forward');
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return $this->verification($context, $snapshot, 'forward');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        $this->assertAuthority($context);
        $this->assertActiveVersion($context, 'rollback');

        return $this->captureSnapshot($context, 'rollback');
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        return $this->transition($context, $snapshot, $cursor, $limit, 'rollback');
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return $this->verification($context, $snapshot, 'rollback');
    }

    private function captureSnapshot(ApplicationMasterRekeyContext $context, string $direction): ApplicationMasterInventorySnapshot
    {
        $rows = $this->rows();
        $roster = [];
        $targetSeen = false;
        foreach ($rows as $row) {
            $classification = $this->classify($context, $row, $direction);
            if ($classification === 'source') {
                if ($targetSeen) {
                    throw new ApplicationMasterRekeyConflictException(
                        'OIDC token custody inventory is not a monotonic source prefix.',
                    );
                }
                $roster[] = $row;
            } else {
                $targetSeen = true;
            }
        }

        return new ApplicationMasterInventorySnapshot(
            $this->snapshotToken($context, $direction, $roster),
            count($roster),
        );
    }

    private function transition(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
        string $direction,
    ): ApplicationMasterBatchResult {
        $this->assertAuthority($context);
        $this->assertActiveVersion($context, $direction);
        if ($limit < 1 || $limit > 10_000) {
            throw new ApplicationMasterRekeyConflictException('OIDC token rekey batch limits are out of range.');
        }
        $roster = $this->resolveRoster($context, $snapshot, $direction);
        $offset = $this->offset($cursor);
        if ($offset >= $snapshot->totalRecords) {
            throw new ApplicationMasterRekeyConflictException('OIDC token rekey cursor has no remaining inventoried row.');
        }
        $batch = array_slice($roster, $offset, $limit);
        $protector = $this->protector($context);
        $commitment = hash('sha256', $this->json([
            'format' => 'waaseyaa.oidc.token-rekey-batch.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $this->adapterId,
            'direction' => $direction,
            'offset' => $offset,
        ]));
        foreach ($batch as $row) {
            if ($this->classify($context, $row, $direction) !== 'source') {
                throw new ApplicationMasterRekeyConflictException('OIDC token source generation changed before transition.');
            }
            $jti = (string) $row['jti'];
            $plaintext = $protector->open((string) $row['token'], $jti);
            if (!in_array((string) $row['token_lookup'], $protector->lookupCandidates($plaintext), true)) {
                throw new ApplicationMasterRekeyConflictException(
                    'OIDC token ciphertext and lookup compare-and-swap source pair is inconsistent.',
                );
            }
            $nextToken = $protector->seal($plaintext, $jti);
            $nextLookup = $protector->lookup($plaintext);
            $updated = $context->database->update($this->table)
                ->fields(['token' => $nextToken, 'token_lookup' => $nextLookup])
                ->condition('jti', $jti)
                ->condition('custody_sequence', (int) $row['custody_sequence'])
                ->condition('token', (string) $row['token'])
                ->condition('token_lookup', (string) $row['token_lookup'])
                ->execute();
            if ($updated !== 1) {
                throw new ApplicationMasterRekeyConflictException(
                    'OIDC token ciphertext and lookup compare-and-swap conflicted.',
                );
            }
            $commitment = hash('sha256', implode("\0", [
                $commitment,
                $jti,
                (string) $row['custody_sequence'],
                hash('sha256', (string) $row['token']),
                hash('sha256', (string) $row['token_lookup']),
                hash('sha256', $nextToken),
                hash('sha256', $nextLookup),
            ]));
        }

        $count = count($batch);
        $nextOffset = $offset + $count;

        return new ApplicationMasterBatchResult(
            'offset:' . $nextOffset,
            $count,
            [$this->encryptionPurpose => $count, $this->lookupPurpose => $count],
            $commitment,
        );
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verification(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $this->assertAuthority($context);
        $this->assertActiveVersion($context, $direction);
        $roster = $this->resolveRoster($context, $snapshot, $direction);
        $protector = $this->protector($context);
        $expectedVersion = $direction === 'forward'
            ? $context->request->toVersion
            : $context->request->fromVersion;
        $commitment = hash('sha256', $this->json([
            'format' => 'waaseyaa.oidc.token-rekey-verification.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $this->adapterId,
            'direction' => $direction,
            'snapshot_token' => $snapshot->token,
        ]));
        foreach ($roster as $row) {
            if ($protector->ciphertextVersion((string) $row['token']) !== $expectedVersion
                || $protector->lookupVersion((string) $row['token_lookup']) !== $expectedVersion) {
                throw new ApplicationMasterRekeyConflictException('OIDC token verification found an untransitioned generation.');
            }
            $plaintext = $protector->open((string) $row['token'], (string) $row['jti']);
            if (!hash_equals((string) $row['token_lookup'], $protector->lookup($plaintext))) {
                throw new ApplicationMasterRekeyConflictException('OIDC token verification found a mismatched sibling lookup index.');
            }
            $commitment = hash('sha256', implode("\0", [
                $commitment,
                (string) $row['jti'],
                (string) $row['custody_sequence'],
                (string) $expectedVersion,
            ]));
        }
        $count = count($roster);
        $verification = new ApplicationMasterPurposeVerification($count, $commitment);

        return [$this->encryptionPurpose => $verification, $this->lookupPurpose => $verification];
    }

    /** @return list<array{jti: mixed, custody_sequence: mixed, token: mixed, token_lookup: mixed}> */
    private function rows(): array
    {
        $rows = iterator_to_array(
            $this->database->select($this->table)
                ->fields($this->table, ['jti', 'custody_sequence', 'token', 'token_lookup'])
                ->orderBy('custody_sequence', 'ASC')
                ->execute(),
            false,
        );
        $expected = 1;
        foreach ($rows as $row) {
            $sequence = $row['custody_sequence'] ?? null;
            if ((!is_int($sequence) && (!is_string($sequence) || preg_match('/^[1-9][0-9]*$/D', $sequence) !== 1))
                || (int) $sequence !== $expected
                || !is_string($row['jti'] ?? null)
                || $row['jti'] === '') {
                throw new ApplicationMasterRekeyConflictException(
                    'OIDC token custody inventory is missing, duplicated, or non-contiguous.',
                );
            }
            ++$expected;
        }

        return $rows;
    }

    /**
     * @return list<array{jti: mixed, custody_sequence: mixed, token: mixed, token_lookup: mixed}>
     */
    private function resolveRoster(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $rows = $this->rows();
        if (count($rows) < $snapshot->totalRecords) {
            throw new ApplicationMasterRekeyConflictException('An inventoried OIDC token row disappeared.');
        }
        $roster = array_slice($rows, 0, $snapshot->totalRecords);
        if (!hash_equals($snapshot->token, $this->snapshotToken($context, $direction, $roster))) {
            throw new ApplicationMasterRekeyConflictException('The OIDC token immutable inventory changed.');
        }

        return $roster;
    }

    /** @param array{token: mixed, token_lookup: mixed} $row */
    private function classify(ApplicationMasterRekeyContext $context, array $row, string $direction): string
    {
        $protector = $this->protector($context);
        $cipherVersion = $protector->ciphertextVersion((string) $row['token']);
        $lookupVersion = $protector->lookupVersion((string) $row['token_lookup']);
        if (($cipherVersion === null) !== ($lookupVersion === null)
            || ($cipherVersion !== null && $cipherVersion !== $lookupVersion)) {
            throw new ApplicationMasterRekeyConflictException('OIDC token ciphertext and lookup generations do not match.');
        }
        $source = $direction === 'forward' ? $context->request->fromVersion : $context->request->toVersion;
        $target = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($direction === 'forward' && $cipherVersion === null) {
            return 'source';
        }
        if ($cipherVersion === $source) {
            return 'source';
        }
        if ($cipherVersion === $target) {
            return 'target';
        }

        throw new ApplicationMasterRekeyConflictException('OIDC token custody contains an undeclared master version.');
    }

    private function protector(ApplicationMasterRekeyContext $context): OpaqueTokenProtector
    {
        return OpaqueTokenProtector::fromApplicationMasterKeyring(
            $context->keyring,
            $this->encryptionPurpose,
            $this->lookupPurpose,
            $this->legacyEncryptionKey?->bytes(),
            $this->legacyLookupKey?->bytes(),
        );
    }

    private function assertAuthority(ApplicationMasterRekeyContext $context): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'OIDC token rekey requires the exact coordinator database authority.',
            );
        }
    }

    private function assertActiveVersion(ApplicationMasterRekeyContext $context, string $direction): void
    {
        $expected = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($context->keyring->activeVersion() !== $expected) {
            throw new ApplicationMasterRekeyConflictException('OIDC token rekey active writer version is not exact.');
        }
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^offset:(0|[1-9][0-9]*)$/D', $cursor, $matches) !== 1) {
            throw new ApplicationMasterRekeyConflictException('OIDC token rekey cursor is malformed.');
        }
        $offset = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($offset) || (string) $offset !== $matches[1]) {
            throw new ApplicationMasterRekeyConflictException('OIDC token rekey cursor is out of range.');
        }

        return $offset;
    }

    /** @param list<array{jti: mixed, custody_sequence: mixed}> $roster */
    private function snapshotToken(ApplicationMasterRekeyContext $context, string $direction, array $roster): string
    {
        $identities = array_map(static fn(array $row): array => [
            'jti' => (string) $row['jti'],
            'custody_sequence' => (int) $row['custody_sequence'],
        ], $roster);

        return hash('sha256', $this->json([
            'format' => 'waaseyaa.oidc.token-rekey-snapshot.v1',
            'request_digest' => $context->request->requestDigest,
            'adapter_id' => $this->adapterId,
            'direction' => $direction,
            'identities' => $identities,
        ]));
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
