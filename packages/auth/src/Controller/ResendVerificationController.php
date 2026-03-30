<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\AuthMailer;

final class ResendVerificationController
{
    public function __construct(
        private readonly AuthConfig $config,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly AuthMailer $authMailer,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Get authenticated account
        $account = $request->attributes->get('_account');
        if ($account === null) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        // 2. Get user ID
        $userId = $account->id();

        // 3. Rate limit: 3 per user per hour
        $rateLimitKey = 'resend_verification:' . $userId;
        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 3)) {
            return new JsonResponse(
                ['error' => 'too_many_attempts'],
                429,
                ['Retry-After' => '3600'],
            );
        }
        $this->rateLimiter->hit($rateLimitKey, 3600);

        // 4. Revoke existing tokens and create a new one
        $this->tokenRepo->revokeTokensForUser($userId, 'email_verification');
        $verifyToken = $this->tokenRepo->createToken(
            $userId,
            'email_verification',
            $this->config->tokenTtl('email_verification'),
        );

        // 5. Load user from storage
        $storage = $this->entityTypeManager->getStorage('user');
        $entity = $storage->load($userId);

        /** @var \Waaseyaa\User\User|null $user */
        $user = $entity;

        if ($user !== null) {
            if ($this->authMailer->isConfigured()) {
                // 6. Mail configured: send verification email
                $this->authMailer->sendEmailVerification($user, $verifyToken);
            } elseif ($this->config->mailMissingPolicy === MailMissingPolicy::DevLog) {
                // 7. DevLog policy: log URL
                error_log('[ResendVerificationController] Email verification URL for user ' . $userId . ': /verify-email?token=' . $verifyToken);
            }
        }

        // 8. Return 200
        return new JsonResponse([
            'ok' => true,
            'message' => 'Verification email sent.',
        ]);
    }
}
