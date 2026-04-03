<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

/**
 * Lightweight HTTP response DTO for controllers that don't need Symfony's Response object.
 *
 * Used by SSR rendering, JSON:API trait, and any controller returning raw HTML or redirects.
 */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $content,
        public readonly int $statusCode = 200,
        public readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {}

    /**
     * HTTP redirect for app controllers that return {@see HttpResponse} (no Symfony Response needed).
     *
     * @param non-empty-string $location URL or path for the `Location` header (caller must validate safety).
     */
    public static function redirect(string $location, int $statusCode = 302): self
    {
        return new self(
            content: '',
            statusCode: $statusCode,
            headers: ['Location' => $location],
        );
    }
}
