<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\User;

final class LoginController
{
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly TwoFactorService $twoFactor,
        private readonly UserIdentityLookupInterface $identityLookup,
        private readonly UserInternalFieldReaderInterface $internalFields,
        ?AuthExtensionRegistry $extensions = null,
        private readonly ?LegacyPasswordUpgrade $passwords = null,
    ) {
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

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

        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $userRepository = $this->entityTypeManager->getRepository('user');
        $candidate = $this->identityLookup->findActiveByLogin($userRepository, $username);
        $user = $candidate instanceof User ? $candidate : null;
        $credentials = $user === null ? null : $this->internalFields->credentials($user);

        // #2544: one verification decision, shared with AuthManager. It also
        // performs the one-time upgrade of a migrated credential, which is why
        // it runs BEFORE the rate-limit clear and the session work below — a
        // failed verification must reach the identical 401 it always did,
        // whatever the stored credential's format was.
        $verified = $user !== null
            && $credentials !== null
            && ($this->passwords !== null
                ? $this->passwords->verify($user, $password, $credentials)
                : $credentials->active
                    && $credentials->passwordHash !== ''
                    && password_verify($password, $credentials->passwordHash));

        if (!$verified) {
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

        $identity = $this->internalFields->sessionIdentity($user);
        $this->extensions->dispatch('login_succeeded', (string) $user->id());

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'data' => [
                'id' => $user->id(),
                'name' => $identity->name,
                'email' => $identity->mail,
                'roles' => $identity->roles,
            ],
            'meta' => ['redirect' => $this->extensions->redirect('login', (string) $user->id())->path],
        ]);
    }
}
