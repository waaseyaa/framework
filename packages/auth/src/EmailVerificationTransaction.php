<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\User\User;

/** Atomic canonical User verification and verification-token consumption. */
final readonly class EmailVerificationTransaction
{
    public function __construct(
        private DatabaseInterface $database,
        private AuthTokenRepositoryInterface $tokenRepository,
    ) {}

    public function complete(
        EntityRepositoryInterface $userRepository,
        User $user,
        int $tokenId,
        int|string $userId,
    ): bool {
        $transaction = $this->database->transaction('auth-email-verification');
        try {
            if (!$this->tokenRepository->consumeTokenIfAvailable($tokenId)) {
                $transaction->rollBack();

                return false;
            }
            $user->setEmailVerified(true);
            $userRepository->save($user);
            $this->tokenRepository->revokeTokensForUser($userId, 'email_verification');
            $transaction->commit();

            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }
}
