<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\AnonymousUser;

#[AsMiddleware(pipeline: 'http', priority: 40)]
final class BearerAuthMiddleware implements HttpMiddlewareInterface
{
    /**
     * @param array<string, int|string> $apiKeys Raw API key => user ID mapping.
     */
    public function __construct(
        private readonly EntityStorageInterface $userStorage,
        private readonly string $jwtSecret = '',
        private readonly array $apiKeys = [],
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $authorizationHeader = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with(strtolower($authorizationHeader), 'bearer ')) {
            return $next->handle($request);
        }

        $token = trim(substr($authorizationHeader, 7));
        if ($token === '') {
            $request->attributes->set('_account', new AnonymousUser());
            return $next->handle($request);
        }

        $account = $this->resolveAccountFromToken($token);
        $request->attributes->set('_account', $account ?? new AnonymousUser());

        return $next->handle($request);
    }

    private function resolveAccountFromToken(string $token): ?AccountInterface
    {
        $uid = $this->resolveUidFromJwt($token);
        if ($uid === null && isset($this->apiKeys[$token])) {
            $uid = $this->apiKeys[$token];
        }

        if ($uid === null) {
            return null;
        }

        try {
            $account = $this->userStorage->load($uid);
        } catch (\Throwable $e) {
            error_log(sprintf('[Waaseyaa] BearerAuthMiddleware: failed to load user %s: %s', $uid, $e->getMessage()));
            return null;
        }

        return $account instanceof AccountInterface ? $account : null;
    }

    /**
     * Returns UID from a verified JWT token.
     */
    private function resolveUidFromJwt(string $token): int|string|null
    {
        if ($this->jwtSecret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $headerRaw = $this->base64UrlDecode($encodedHeader);
        $payloadRaw = $this->base64UrlDecode($encodedPayload);
        $signatureRaw = $this->base64UrlDecode($encodedSignature);

        if ($headerRaw === null || $payloadRaw === null || $signatureRaw === null) {
            return null;
        }

        try {
            $header = json_decode($headerRaw, true, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $this->jwtSecret, true);
        if (!hash_equals($expectedSignature, $signatureRaw)) {
            return null;
        }

        $now = time();
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] <= $now) {
            return null;
        }

        $uid = $payload['uid'] ?? $payload['sub'] ?? null;
        if (is_int($uid) || is_string($uid)) {
            return $uid;
        }

        return null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = str_replace(['-', '_'], ['+', '/'], $value);
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }
}
