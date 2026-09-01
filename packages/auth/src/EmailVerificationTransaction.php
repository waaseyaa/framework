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
    private const string TOKEN_TYPE = 'email_verification';

    public function __construct(
        private DatabaseInterface $database,
        private AuthTokenRepositoryInterface $tokenRepository,
    ) {}

    /**
     * The atomic consume is the single admission decision for this operation.
     *
     * It is bound to the identity of the User this call is about to mark
     * verified, not to a separately supplied id, so a token can only ever
     * verify the account that owns it. Type and expiry are re-checked by that
     * same write, so nothing an earlier validateToken() observed is trusted to
     * still hold here.
     */
    public function complete(
        EntityRepositoryInterface $userRepository,
        User $user,
        int $tokenId,
    ): bool {
        $transaction = $this->database->transaction('auth-email-verification');
        try {
            $userId = $user->id();
            if (!$this->tokenRepository->consumeTokenIfAvailable($tokenId, self::TOKEN_TYPE, $userId)) {
                $transaction->rollBack();

                return false;
            }
            $user->setEmailVerified(true);
            $userRepository->save($user);
            $this->tokenRepository->revokeTokensForUser($userId, self::TOKEN_TYPE);
            $transaction->commit();

            return true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
    }
}
