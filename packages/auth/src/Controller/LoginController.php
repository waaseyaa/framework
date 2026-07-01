<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Http\AuthController;

final class LoginController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        $rateLimitKey = 'login:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 5)) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '429', 'title' => 'Too Many Requests', 'detail' => 'Too many login attempts. Please try again later.']],
            ], 429, ['Retry-After' => '60']);
        }

        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Request body is not valid JSON.']],
            ], 400);
        }
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        if ($username === '' || $password === '') {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'username and password are required.']],
            ], 400);
        }

        $userStorage = $this->entityTypeManager->getStorage('user');
        // C-22 WP2: the query builder now lives on the repository.
        $userRepository = $this->entityTypeManager->getRepository('user');
        $authController = new AuthController();
        $user = $authController->findUserByName($userStorage, $userRepository, $username);

        if ($user === null || !$user->isActive() || !$user->checkPassword($password)) {
            $this->rateLimiter->hit($rateLimitKey, 60);
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '401', 'title' => 'Unauthorized', 'detail' => 'Invalid credentials.']],
            ], 401);
        }

        $this->rateLimiter->clear($rateLimitKey);

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Session not available. Login cannot be completed.']],
            ], 500);
        }

        // If 2FA is enabled, do NOT issue a session yet. Store the user's UID
        // under a pending key; the client must POST to /auth/2fa/verify with a
        // TOTP or recovery code to complete login. VerifyTwoFactorController
        // detects the pending key and promotes it to a full session.
        if ($this->twoFactor->isEnabled($user)) {
            $_SESSION['waaseyaa_pending_2fa_uid'] = $user->id();

            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'data' => [
                    'type' => 'auth',
                    'attributes' => [
                        'state' => '2fa_required',
                        'pending_user_id' => $user->id(),
                    ],
                ],
            ]);
        }

        $_SESSION['waaseyaa_uid'] = $user->id();
        session_regenerate_id(true);

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'data' => [
                'id' => $user->id(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }
}
