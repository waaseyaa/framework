<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Password;

use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\User\User;

/** Framework-owned limited credential-change proof, never an authenticated session. @api */
final readonly class LegacyPasswordChange
{
    public function __construct(
        private AuthConfig $config,
        private LegacyPasswordVerifierChain $verifiers,
        private UserInternalFieldReaderInterface $internalFields,
        private AuthTokenRepositoryInterface $tokens,
        private DatabaseInterface $database,
    ) {}

    public function required(UserCredentialSnapshot $credentials): bool
    {
        return $this->config->requireLegacyPasswordChange
            && $credentials->passwordHash === ''
            && $credentials->legacyPasswordHash !== null
            && $credentials->legacyPasswordHash !== '';
    }

    public function challenge(User $user, string $password): ?string
    {
        $credentials = $this->internalFields->credentials($user);
        $verification = $this->internalFields->verification($user);
        if (!$this->required($credentials) || !$credentials->active
            || ($this->config->requireVerifiedEmail && !$verification->emailVerified)
            || !$this->verifiers->verify($password, $credentials->legacyPasswordHash ?? '')) {
            return null;
        }

        return $this->tokens->createToken($user->id(), 'password_reset', 300, [
            'purpose' => 'legacy_password_change',
            'credential' => hash('sha256', $credentials->legacyPasswordHash ?? ''),
            'generation' => $this->internalFields->sessionIdentity($user)->generation,
        ]);
    }

    /** Atomically authorizes and completes both emailed recovery and limited login proofs. */
    public function complete(EntityRepositoryInterface $repository, array $token, string $password): ?string
    {
        if (!$repository instanceof \Waaseyaa\EntityStorage\EntityRepository
            || !$repository->sharesTransactionWith($this->database)
            || !$this->tokens instanceof \Waaseyaa\Auth\Token\AuthTokenRepository
            || !$this->tokens->sharesTransactionWith($this->database)) {
            throw new \LogicException('Password reset requires canonical stores sharing one managed transaction.');
        }
        $transaction = $this->database->transaction('auth-password-reset');
        try {
            // Consume first: this conditional write locks out competing completions.
            if (!$this->tokens->consumeTokenIfAvailable($token['id'], 'password_reset', $token['user_id'])) {
                $transaction->rollBack();
                return 'invalid_or_expired_token';
            }
            $user = $repository->find((string) $token['user_id']);
            if (!$user instanceof User) {
                $transaction->rollBack();
                return 'invalid_or_expired_token';
            }
            $credentials = $this->internalFields->credentials($user);
            $generation = $this->internalFields->sessionIdentity($user)->generation;
            $proof = $token['meta'] ?? [];
            if (!$credentials->active || (($proof['purpose'] ?? null) === 'legacy_password_change'
                && (!$this->required($credentials)
                    || !hash_equals((string) ($proof['credential'] ?? ''), hash('sha256', $credentials->legacyPasswordHash ?? ''))
                    || ($proof['generation'] ?? null) !== $generation))) {
                $transaction->rollBack();
                return 'invalid_or_expired_token';
            }
            if ($this->required($credentials)
                && $this->verifiers->verify($password, $credentials->legacyPasswordHash ?? '')) {
                $transaction->rollBack();
                return 'different_password_required';
            }
            $user->setRawPassword($password);
            $user->setLegacyPassword(null);
            $user->setSessionGeneration($generation + 1);
            $repository->save($user);
            $this->tokens->revokeTokensForUser($user->id(), 'password_reset');
            $transaction->commit();
            return null;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }
}
