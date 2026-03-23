<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Waaseyaa\Foundation\Log\ErrorLogHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;

final class ResponseSender
{
    private static ?LoggerInterface $logger = null;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= new ErrorLogHandler();
    }

    /**
     * Send a JSON:API response and terminate.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $data, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/vnd.api+json');
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }
        try {
            echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::logger()->error(sprintf('JSON encoding failed in sendJson: %s', $e->getMessage()));
            echo '{"jsonapi":{"version":"1.1"},"errors":[{"status":"500","title":"Internal Server Error","detail":"Response encoding failed."}]}';
        }
        exit;
    }

    /**
     * Send an HTML response and terminate.
     *
     * @param array<string, string> $headers
     */
    public static function html(int $status, string $html, array $headers = []): never
    {
        http_response_code($status);
        $contentType = $headers['Content-Type'] ?? 'text/html; charset=UTF-8';
        header('Content-Type: ' . $contentType);

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                continue;
            }
            header($name . ': ' . $value);
        }

        echo $html;
        exit;
    }
}
