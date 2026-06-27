<?php

declare(strict_types=1);

namespace Waaseyaa\Mercure;

/**
 * @api
 */
final class MercurePublisher
{
    public function __construct(
        private readonly string $hubUrl,
        private readonly string $jwtSecret,
    ) {}

    public function publish(string $topic, array $data): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $ch = curl_init($this->hubUrl);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $this->buildPostBody($topic, $data),
            CURLOPT_HTTPHEADER => [
                // Scope the bearer token to the exact topic being published, so a
                // leaked token cannot be used to publish to any other topic.
                'Authorization: Bearer ' . $this->generateJwt($topic),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            // Enforce TLS verification explicitly — never trust an ambient php.ini
            // that may have disabled it. isConfigured() already requires https://,
            // so the token is only ever sent over a verified, encrypted channel.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $result !== false && $httpCode >= 200 && $httpCode < 300;
    }

    public function isConfigured(): bool
    {
        // Require an https:// hub: the bearer token is short-lived but still
        // grants publish access, and shipping it to a cleartext http:// hub
        // leaks it in transit. A non-https hub is treated as unconfigured so
        // publish() returns false without ever minting or sending a token.
        return $this->jwtSecret !== '' && self::isHttpsUrl($this->hubUrl);
    }

    private function generateJwt(string $topic): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            // Least privilege: grant publish on this topic only, never ['*'].
            'mercure' => ['publish' => [$topic]],
            'iat' => time(),
            'exp' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $this->jwtSecret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    private function buildPostBody(string $topic, array $data): string
    {
        return http_build_query([
            'topic' => $topic,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
        ]);
    }

    private static function isHttpsUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return \is_string($scheme) && strtolower($scheme) === 'https';
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
