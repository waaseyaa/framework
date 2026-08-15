<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\SensitiveKey;
use Waaseyaa\Oidc\Security\SecretBoxEnvelope;

/** Signing-private-material application-master owner. @api */
final class OidcSigningKeyRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'oidc-signing-key-v1';

    private const string TABLE = 'oidc_signing_key';
    private const string PURPOSE = ApplicationSecret::PURPOSE_OIDC_SIGNING_KEY_ENCRYPTION;

    private readonly ?SensitiveKey $legacyEncryptionKey;

    public function __construct(
        private readonly DatabaseInterface $database,
        #[\SensitiveParameter]
        ?string $legacyEncryptionKey = null,
    ) {
        if ($legacyEncryptionKey !== null && strlen($legacyEncryptionKey) !== 32) {
            throw new \InvalidArgumentException('OIDC signing legacy encryption keys must be 32 bytes.');
        }
        $this->legacyEncryptionKey = $legacyEncryptionKey === null ? null : new SensitiveKey($legacyEncryptionKey);
    }

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
        return [self::PURPOSE];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
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

    public function verify(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verification($context, $snapshot, 'forward');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
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

    public function verifyRollback(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verification($context, $snapshot, 'rollback');
    }

    private function captureSnapshot(ApplicationMasterRekeyContext $context, string $direction): ApplicationMasterInventorySnapshot
    {
        $this->assertBoundary($context, $direction);
        $rows = $this->rows();
        $roster = [];
        $targetSeen = false;
        foreach ($rows as $row) {
            $classification = $this->classify($context, $row, $direction);
            if ($classification === 'source') {
                if ($targetSeen) {
                    throw new ApplicationMasterRekeyConflictException('OIDC signing custody inventory is not a monotonic source prefix.');
                }
                $roster[] = $row;
            } else {
                $targetSeen = true;
            }
        }

        return new ApplicationMasterInventorySnapshot($this->snapshotToken($context, $direction, $roster), count($roster));
    }

    private function transition(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
        string $direction,
    ): ApplicationMasterBatchResult {
        $this->assertBoundary($context, $direction);
        if ($limit < 1 || $limit > 10_000) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey batch limits are out of range.');
        }
        $roster = $this->resolveRoster($context, $snapshot, $direction);
        $offset = $this->offset($cursor);
        if ($offset >= $snapshot->totalRecords) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey cursor has no remaining inventoried row.');
        }
        $batch = array_slice($roster, $offset, $limit);
        $custody = $this->custody($context);
        $commitment = hash('sha256', implode("\0", [
            'waaseyaa.oidc.signing-rekey-batch.v1',
            $context->request->requestDigest,
            $direction,
            (string) $offset,
        ]));
        foreach ($batch as $row) {
            if ($this->classify($context, $row, $direction) !== 'source') {
                throw new ApplicationMasterRekeyConflictException('OIDC signing source generation changed before transition.');
            }
            $kid = (string) $row['kid'];
            $plaintext = $custody->open((string) $row['private_key_pem'], $kid);
            $next = $custody->seal($plaintext, $kid);
            $updated = $context->database->update(self::TABLE)
                ->fields(['private_key_pem' => $next])
                ->condition('kid', $kid)
                ->condition('key_version', (int) $row['key_version'])
                ->condition('private_key_pem', (string) $row['private_key_pem'])
                ->execute();
            if ($updated !== 1) {
                throw new ApplicationMasterRekeyConflictException('OIDC signing ciphertext compare-and-swap conflicted.');
            }
            $commitment = hash('sha256', implode("\0", [
                $commitment,
                $kid,
                (string) $row['key_version'],
                hash('sha256', (string) $row['private_key_pem']),
                hash('sha256', $next),
            ]));
        }
        $count = count($batch);

        return new ApplicationMasterBatchResult(
            'offset:' . ($offset + $count),
            $count,
            [self::PURPOSE => $count],
            $commitment,
        );
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verification(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $this->assertBoundary($context, $direction);
        $roster = $this->resolveRoster($context, $snapshot, $direction);
        $custody = $this->custody($context);
        $expectedVersion = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        $commitment = hash('sha256', implode("\0", [
            'waaseyaa.oidc.signing-rekey-verification.v1',
            $context->request->requestDigest,
            $direction,
            $snapshot->token,
        ]));
        foreach ($roster as $row) {
            if ($custody->applicationMasterVersion((string) $row['private_key_pem']) !== $expectedVersion) {
                throw new ApplicationMasterRekeyConflictException('OIDC signing verification found an untransitioned generation.');
            }
            $plaintext = $custody->open((string) $row['private_key_pem'], (string) $row['kid']);
            if ($plaintext === '') {
                throw new ApplicationMasterRekeyConflictException('OIDC signing verification found empty private material.');
            }
            $commitment = hash('sha256', implode("\0", [
                $commitment,
                (string) $row['kid'],
                (string) $row['key_version'],
                (string) $expectedVersion,
            ]));
        }

        return [self::PURPOSE => new ApplicationMasterPurposeVerification(count($roster), $commitment)];
    }

    /** @return list<array{kid: mixed, key_version: mixed, private_key_pem: mixed}> */
    private function rows(): array
    {
        $rows = iterator_to_array(
            $this->database->select(self::TABLE)
                ->fields(self::TABLE, ['kid', 'key_version', 'private_key_pem'])
                ->orderBy('key_version', 'ASC')
                ->execute(),
            false,
        );
        $lastVersion = 0;
        foreach ($rows as $row) {
            $version = $row['key_version'] ?? null;
            if ((!is_int($version) && (!is_string($version) || preg_match('/^[1-9][0-9]*$/D', $version) !== 1))
                || (int) $version <= $lastVersion
                || !is_string($row['kid'] ?? null)
                || $row['kid'] === '') {
                throw new ApplicationMasterRekeyConflictException('OIDC signing custody inventory is malformed or duplicated.');
            }
            $lastVersion = (int) $version;
        }

        return $rows;
    }

    /** @return list<array{kid: mixed, key_version: mixed, private_key_pem: mixed}> */
    private function resolveRoster(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $rows = $this->rows();
        if (count($rows) < $snapshot->totalRecords) {
            throw new ApplicationMasterRekeyConflictException('An inventoried OIDC signing key disappeared.');
        }
        $roster = array_slice($rows, 0, $snapshot->totalRecords);
        if (!hash_equals($snapshot->token, $this->snapshotToken($context, $direction, $roster))) {
            throw new ApplicationMasterRekeyConflictException('The OIDC signing immutable inventory changed.');
        }

        return $roster;
    }

    /** @param array{private_key_pem: mixed} $row */
    private function classify(ApplicationMasterRekeyContext $context, array $row, string $direction): string
    {
        $version = $this->custody($context)->applicationMasterVersion((string) $row['private_key_pem']);
        $source = $direction === 'forward' ? $context->request->fromVersion : $context->request->toVersion;
        $target = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($direction === 'forward' && $version === null) {
            return 'source';
        }
        if ($version === $source) {
            return 'source';
        }
        if ($version === $target) {
            return 'target';
        }

        throw new ApplicationMasterRekeyConflictException('OIDC signing custody contains an undeclared master version.');
    }

    private function custody(ApplicationMasterRekeyContext $context): SecretBoxEnvelope
    {
        return SecretBoxEnvelope::fromApplicationMasterKeyring(
            $context->keyring,
            self::PURPOSE,
            $this->legacyEncryptionKey?->bytes(),
        );
    }

    private function assertBoundary(ApplicationMasterRekeyContext $context, string $direction): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey requires the exact coordinator database authority.');
        }
        $expected = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($context->keyring->activeVersion() !== $expected) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey active writer version is not exact.');
        }
    }

    private function offset(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }
        if (preg_match('/^offset:(0|[1-9][0-9]*)$/D', $cursor, $matches) !== 1) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey cursor is malformed.');
        }
        $offset = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($offset) || (string) $offset !== $matches[1]) {
            throw new ApplicationMasterRekeyConflictException('OIDC signing rekey cursor is out of range.');
        }

        return $offset;
    }

    /** @param list<array{kid: mixed, key_version: mixed}> $roster */
    private function snapshotToken(ApplicationMasterRekeyContext $context, string $direction, array $roster): string
    {
        return hash('sha256', json_encode([
            'format' => 'waaseyaa.oidc.signing-rekey-snapshot.v1',
            'request_digest' => $context->request->requestDigest,
            'direction' => $direction,
            'identities' => array_map(static fn(array $row): array => [
                'kid' => (string) $row['kid'],
                'key_version' => (int) $row['key_version'],
            ], $roster),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
