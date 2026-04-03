<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;

final class RegisterController
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
        // 1. Check registration mode
        if ($this->config->registration === 'admin') {
            return new JsonResponse(['error' => 'registration_disabled'], 403);
        }

        // 2. Rate limiting: 5 attempts per IP per 15 minutes
        $ip = $request->getClientIp() ?? 'unknown';
        $rateLimitKey = 'register:' . $ip;
        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 5)) {
            return new JsonResponse(['error' => 'too_many_attempts'], 429);
        }
        $this->rateLimiter->hit($rateLimitKey, 900);

        // 3. Parse JSON body
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $inviteToken = (string) ($body['invite_token'] ?? '');

        // 4. Validate fields
        $errors = [];

        if (strlen($name) < 2 || strlen($name) > 255) {
            $errors['name'] = 'Name must be between 2 and 255 characters.';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        // 5. Invite mode: validate invite token
        $inviteTokenData = null;

        if ($this->config->registration === 'invite') {
            if ($inviteToken === '') {
                $errors['invite_token'] = 'An invite token is required.';
            } else {
                $inviteTokenData = $this->tokenRepo->validateToken($inviteToken, 'invite');
                if ($inviteTokenData === null) {
                    $errors['invite_token'] = 'Invalid or expired invite token.';
                }
            }
        }

        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        // 6. Check email uniqueness (anti-enumeration: generic 422)
        $storage = $this->entityTypeManager->getStorage('user');
        $existing = $storage->loadByKey('mail', $email);

        if ($existing !== null) {
            return new JsonResponse(['errors' => ['email' => 'Registration failed. Please try again.']], 422);
        }

        // 7. Create User entity
        $emailVerified = $this->config->registration === 'invite' ? 1 : 0;

        $user = new User([
            'name' => $name,
            'mail' => $email,
            'status' => 1,
            'email_verified' => $emailVerified,
        ]);

        // 8. Set password, mark new, save
        $user->setRawPassword($password);
        $user->enforceIsNew();
        $storage->save($user);

        // 9. Consume invite token if applicable
        if ($this->config->registration === 'invite' && $inviteTokenData !== null) {
            $this->tokenRepo->consumeToken($inviteTokenData['id']);
        }

        // 10. Open mode: send verification email (or dev-log)
        if ($this->config->registration === 'open') {
            $verifyToken = $this->tokenRepo->createToken(
                $user->id(),
                'email_verification',
                $this->config->tokenTtl('email_verification'),
            );

            if ($this->authMailer->isConfigured()) {
                $this->authMailer->sendEmailVerification($user, $verifyToken);
            } elseif ($this->config->mailMissingPolicy === \Waaseyaa\Auth\Config\MailMissingPolicy::DevLog) {
                $this->logger->info('Email verification URL for ' . $email . ': /verify-email?token=' . $verifyToken);
            }
        }

        // 11. Send welcome email (best-effort)
        try {
            $this->authMailer->sendWelcome($user);
        } catch (\Throwable $e) {
            $this->logger->warning('Welcome email failed: ' . $e->getMessage());
        }

        // 12. Auto-login: regenerate session, set waaseyaa_uid
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['waaseyaa_uid'] = $user->id();

        // 13. Return 201 with user data
        return new JsonResponse([
            'data' => [
                'id' => $user->id(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'email_verified' => $user->isEmailVerified(),
            ],
        ], 201);
    }
}
