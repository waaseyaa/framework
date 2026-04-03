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
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\AuthMailer;

final class ForgotPasswordController
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AuthConfig $config,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly AuthMailer $authMailer,
        private readonly RateLimiter $rateLimiter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Parse JSON body, extract email
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $email = trim((string) ($body['email'] ?? ''));

        // 2. Return 422 if email empty
        if ($email === '') {
            return new JsonResponse(['error' => 'email_required'], 422);
        }

        // 3. Check mail configured; apply mailMissingPolicy if not
        $mailConfigured = $this->authMailer->isConfigured();
        if (!$mailConfigured && $this->config->mailMissingPolicy === MailMissingPolicy::Fail) {
            return new JsonResponse(['error' => 'mail_not_configured'], 503);
        }

        // 4. Rate limit: 3 per email per 15 min, 10 per IP per hour
        $emailKey = 'forgot:email:' . $email;
        if ($this->rateLimiter->tooManyAttempts($emailKey, 3)) {
            return new JsonResponse(['error' => 'too_many_attempts'], 429);
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $ipKey = 'forgot:ip:' . $ip;
        if ($this->rateLimiter->tooManyAttempts($ipKey, 10)) {
            return new JsonResponse(['error' => 'too_many_attempts'], 429);
        }
        $this->rateLimiter->hit($emailKey, 900);
        $this->rateLimiter->hit($ipKey, 3600);

        // 5. Look up user by email
        $storage = $this->entityTypeManager->getStorage('user');
        $entity = $storage->loadByKey('mail', $email);

        /** @var \Waaseyaa\User\User|null $user */
        $user = $entity;
        if ($user !== null) {
            $ttl = $this->config->tokenTtl('password_reset');
            $token = $this->tokenRepo->createToken($user->id(), 'password_reset', $ttl);

            if ($mailConfigured) {
                // 6. User found + mail configured: send email
                $this->authMailer->sendPasswordReset($user, $token);
            } elseif ($this->config->mailMissingPolicy === MailMissingPolicy::DevLog) {
                // 7. User found + not configured + DevLog: log reset URL
                $this->logger->info('Password reset URL for ' . $email . ': /reset-password?token=' . $token);
            }
            // Silent policy: create token but do nothing
        }
        // 8. User not found: do nothing (anti-enumeration)

        // 9. Always return 200
        return new JsonResponse([
            'ok' => true,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }
}
