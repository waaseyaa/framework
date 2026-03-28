<?php

declare(strict_types=1);

namespace Waaseyaa\Mercure;

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
                'Authorization: Bearer ' . $this->generateJwt(),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $httpCode >= 200 && $httpCode < 300;
    }

    public function isConfigured(): bool
    {
        return $this->hubUrl !== '' && $this->jwtSecret !== '';
    }

    private function generateJwt(): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::base64UrlEncode(json_encode([
            'mercure' => ['publish' => ['*']],
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

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
