<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Diagnostic;

/** Self-probes a known non-root route through the public web server. */
final class CleanUrlProbe
{
    public const string PATH = '/.well-known/waaseyaa/clean-url';
    public const string SENTINEL = 'waaseyaa-clean-url-ok';

    /** @var \Closure(string): array{status: int, body: string} */
    private readonly \Closure $request;

    /** @param null|\Closure(string): array{status: int, body: string} $request */
    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $request = null,
    ) {
        $this->request = $request ?? $this->request(...);
    }

    public function check(): HealthCheckResult
    {
        try {
            $response = ($this->request)($this->probeUrl());
        } catch (\Throwable $e) {
            return HealthCheckResult::fail(
                'Clean URL routing',
                DiagnosticCode::CLEAN_URL_ROUTING_UNREACHABLE,
                'The clean-URL self-probe could not reach the front controller: ' . $e->getMessage(),
            );
        }

        if ($response['status'] === 200 && trim($response['body']) === self::SENTINEL) {
            return HealthCheckResult::pass(
                'Clean URL routing',
                'The known non-root route reached the Waaseyaa router.',
            );
        }

        return HealthCheckResult::fail(
            'Clean URL routing',
            DiagnosticCode::CLEAN_URL_ROUTING_UNREACHABLE,
            sprintf(
                'The clean-URL self-probe returned HTTP %d without the router sentinel; the web server may be serving only DirectoryIndex instead of forwarding requests to the front controller.',
                $response['status'],
            ),
            context: ['status' => $response['status']],
        );
    }

    private function probeUrl(): string
    {
        return rtrim($this->baseUrl, '/') . self::PATH;
    }

    /** @return array{status: int, body: string} */
    private function request(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 5,
                'header' => "Accept: text/plain\r\nConnection: close\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = http_get_last_response_headers();
        $statusLine = is_array($headers) ? ($headers[0] ?? '') : '';

        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            throw new \RuntimeException('self-probe connection failed');
        }

        return [
            'status' => (int) $matches[1],
            'body' => is_string($body) ? $body : '',
        ];
    }
}
