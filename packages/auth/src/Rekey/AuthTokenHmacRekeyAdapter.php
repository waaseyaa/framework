<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/**
 * Outstanding reset/verify/invite HMAC rows cannot be rehashed; they drain or expire.
 *
 * @api
 */
final readonly class AuthTokenHmacRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'auth-token-hmac-v1';

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
        return [ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->zeroSnapshot($context, 'forward');
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'Auth-token HMAC hashes cannot be rehashed; drain or expire outstanding tokens before rotation.',
        );
    }

    public function verify(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyZero($context, $snapshot, 'forward');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->zeroSnapshot($context, 'rollback');
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'Auth-token HMAC rollback cannot restore plaintext tokens.',
        );
    }

    public function verifyRollback(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyZero($context, $snapshot, 'rollback');
    }

    private function zeroSnapshot(ApplicationMasterRekeyContext $context, string $direction): ApplicationMasterInventorySnapshot
    {
        $this->assertBoundary($context, $direction);
        if ($this->outstandingCount() !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'Outstanding auth tokens must drain or expire before the application-master snapshot.',
            );
        }

        return new ApplicationMasterInventorySnapshot($this->token($context, $direction), 0);
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verifyZero(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $this->assertBoundary($context, $direction);
        if ($snapshot->totalRecords !== 0 || !hash_equals($snapshot->token, $this->token($context, $direction))) {
            throw new ApplicationMasterRekeyConflictException('Auth-token HMAC snapshot is not exact.');
        }
        if ($this->outstandingCount() !== 0) {
            throw new ApplicationMasterRekeyConflictException(
                'An outstanding auth token appeared after the drain snapshot.',
            );
        }

        return [ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC => new ApplicationMasterPurposeVerification(
            0,
            hash('sha256', implode("\0", [
                'waaseyaa.auth.token-hmac-verification.v1',
                $context->request->requestDigest,
                $direction,
                $snapshot->token,
            ])),
        )];
    }

    private function assertBoundary(ApplicationMasterRekeyContext $context, string $direction): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'Auth-token HMAC rekey requires the exact coordinator database authority.',
            );
        }
        $expected = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($context->keyring->activeVersion() !== $expected) {
            throw new ApplicationMasterRekeyConflictException('Auth-token HMAC active writer version is not exact.');
        }
    }

    private function outstandingCount(): int
    {
        if (!$this->database->schema()->tableExists('auth_tokens')) {
            return 0;
        }

        $now = time();
        $total = 0;
        $query = $this->database->select('auth_tokens', 'auth_token_rekey')
            ->condition('expires_at', $now, '>')
            ->isNull('consumed_at');
        foreach ($query->countQuery()->execute() as $row) {
            $total += (int) reset($row);
            break;
        }

        return $total;
    }

    private function token(ApplicationMasterRekeyContext $context, string $direction): string
    {
        return hash('sha256', implode("\0", [
            'waaseyaa.auth.token-hmac-snapshot.v1',
            $context->request->requestDigest,
            $direction,
            'records:0',
        ]));
    }
}
