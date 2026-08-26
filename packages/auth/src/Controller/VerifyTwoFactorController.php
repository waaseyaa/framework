<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\User\User;

/**
 * POST /auth/2fa/verify — submit a TOTP or recovery code.
 *
 * Rate-limited identically to the login endpoint (5 attempts per IP per 60s)
 * under the distinct `2fa-verify:` namespace. Two dispatch modes:
 *
 *   - **Pending-login mode:** `_account` is not set but `$_SESSION` carries
 *     `waaseyaa_pending_2fa_uid` (placed there by LoginController when 2FA
 *     is enabled). The controller loads that user, verifies the submitted
 *     code, and on success promotes the session to a full login (sets
 *     `waaseyaa_uid`, clears the pending key, regenerates the session id).
 *
 *   - **Authenticated re-verify mode:** `_account` is a User instance.
 *     Used to re-confirm 2FA for sensitive operations (e.g. disable). No
 *     session promotion happens here.
 *
 * @api
 */
final class VerifyTwoFactorController
{
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        ?AuthExtensionRegistry $extensions = null,
    ) {
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

    public function __invoke(Request $request): JsonResponse
    {
        [$user, $isPending] = $this->resolveUser($request);
        if ($user === null) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '401', 'title' => 'Unauthorized', 'detail' => 'Authentication required.']],
            ], 401);
        }

        if (!$this->twoFactor->isEnabled($user)) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Two-Factor Not Enabled', 'detail' => 'Two-factor authentication is not enabled for this user.']],
            ], 400);
        }

        $ip = $request->getClientIp() ?? '127.0.0.1';
        $key = '2fa-verify:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($key, 5)) {
            $response = new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '429', 'title' => 'Too Many Requests', 'detail' => 'Too many verification attempts. Please try again later.']],
            ], 429);
            $response->headers->set('Retry-After', '60');
            return $response;
        }

        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'Request body is not valid JSON.']],
            ], 400);
        }

        $code = is_string($body['code'] ?? null) ? $body['code'] : '';
        if ($code === '') {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => 'code is required.']],
            ], 400);
        }

        if (!$this->twoFactor->verify($user, $code)) {
            $this->rateLimiter->hit($key, 60);
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '401', 'title' => 'Invalid Code', 'detail' => 'The submitted code is not valid.']],
            ], 401);
        }

        if ($isPending) {
            $_SESSION['waaseyaa_uid'] = $user->id();
            unset($_SESSION['waaseyaa_pending_2fa_uid']);
            if (session_status() === \PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $this->extensions->dispatch('login_succeeded', (string) $user->id(), ['two_factor' => true]);
        }

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'data' => ['type' => 'two-factor', 'attributes' => ['verified' => true]],
            'meta' => $isPending
                ? ['redirect' => $this->extensions->redirect('login', (string) $user->id())->path]
                : [],
        ]);
    }

    /**
     * @return array{0: ?User, 1: bool} [user, isPending]
     */
    private function resolveUser(Request $request): array
    {
        $account = $request->attributes->get('_account');
        if ($account instanceof User) {
            return [$account, false];
        }

        $pendingUid = $_SESSION['waaseyaa_pending_2fa_uid'] ?? null;
        if (!is_int($pendingUid)) {
            return [null, false];
        }

        // C-22 WP3: read path now goes through the canonical repository.
        $loaded = $this->entityTypeManager->getRepository('user')->find((string) $pendingUid);
        if (!$loaded instanceof User) {
            return [null, false];
        }

        return [$loaded, true];
    }
}
