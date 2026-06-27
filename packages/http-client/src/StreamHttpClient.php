<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient;

final class StreamHttpClient implements HttpClientInterface
{
    /** Hard ceiling on the response body read into memory (worker-safety, m4). */
    private const DEFAULT_MAX_RESPONSE_BYTES = 16 * 1024 * 1024; // 16 MiB

    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {}

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        // SSRF surface reduction (M1): only http/https. Reject file://, ftp://,
        // php://, data:// etc. before the stream wrapper ever sees the URL.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpRequestException(
                sprintf('Unsupported URL scheme "%s" (only http/https allowed): %s', $scheme, $url),
                $url,
                $method,
            );
        }

        $httpHeaders = [];
        foreach ($headers as $name => $value) {
            // CRLF guard (n1): a \r or \n in a header name/value lets a caller
            // inject extra headers or split the request — reject before
            // serialization. Also hardens mail's header path at the transport layer.
            $this->assertNoHeaderInjection($url, $method, $name, $value);
            $httpHeaders[] = "{$name}: {$value}";
        }

        $content = null;
        if ($body !== null) {
            if (is_array($body)) {
                $content = json_encode($body, JSON_THROW_ON_ERROR);
                if (!isset($headers['Content-Type'])) {
                    $httpHeaders[] = 'Content-Type: application/json';
                }
            } else {
                $content = $body;
            }
        }

        $context = stream_context_create($this->buildContextOptions($method, $httpHeaders, $content));

        $responseBody = $this->fetch($url, $method, $context);

        /** @var list<string> $responseHeaderLines */
        $responseHeaderLines = http_get_last_response_headers() ?? [];
        $statusCode = $this->parseStatusCode($responseHeaderLines);
        $responseHeaders = $this->parseHeaders($responseHeaderLines);

        return new HttpResponse($statusCode, $responseBody, $responseHeaders);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * Build the stream-context options. Extracted so the security-relevant
     * defaults (no redirect following, TLS verification) can be unit-pinned.
     *
     * @param list<string> $httpHeaders
     * @return array{http: array<string, mixed>, ssl: array<string, mixed>}
     */
    private function buildContextOptions(string $method, array $httpHeaders, ?string $content): array
    {
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $httpHeaders),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                // M1 (credential-leak / SSRF pivot): do NOT auto-follow redirects.
                // PHP otherwise follows up to 20 hops and re-sends the caller's
                // Authorization header to the redirect target — including a
                // different host. We return the 3xx response and let the caller
                // decide whether to follow (and re-authenticate), so credentials
                // are never silently re-sent to any redirect target.
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            // m1 (TLS): pin certificate verification rather than relying on the
            // ambient php.ini (on by default, but make it explicit and immune to
            // a hostile/loose runtime config).
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ];

        if ($content !== null) {
            $options['http']['content'] = $content;
        }

        return $options;
    }

    /**
     * Open the URL and read at most {@see $maxResponseBytes} of the body, with a
     * bounded connect phase, capturing the underlying error on failure.
     *
     * @param resource $context
     */
    private function fetch(string $url, string $method, $context): string
    {
        error_clear_last();

        // m3: bound the connect phase (incl. a stalled TLS handshake), not just
        // the read timeout — `default_socket_timeout` governs the connect for the
        // http(s) wrapper. Set it to our timeout for the duration of this call and
        // restore it so we don't mutate global state for the rest of the worker.
        $previousSocketTimeout = ini_set('default_socket_timeout', (string) (int) ceil($this->timeout));

        try {
            $handle = @fopen($url, 'rb', false, $context);
            if ($handle === false) {
                throw $this->transportFailure($method, $url);
            }

            try {
                // m4: cap the body so a runaway/hostile endpoint can't OOM the worker.
                $responseBody = @stream_get_contents($handle, $this->maxResponseBytes);
            } finally {
                fclose($handle);
            }
        } finally {
            if ($previousSocketTimeout !== false) {
                ini_set('default_socket_timeout', $previousSocketTimeout);
            }
        }

        if ($responseBody === false) {
            throw $this->transportFailure($method, $url);
        }

        return $responseBody;
    }

    /**
     * m5: surface the underlying transport error (DNS, refused, TLS, timeout)
     * instead of a bare "request failed" message, both in the message and as a
     * chained \ErrorException previous, so failures are debuggable.
     */
    private function transportFailure(string $method, string $url): HttpRequestException
    {
        $error = error_get_last();
        $detail = $error !== null ? ': ' . $error['message'] : '';
        $previous = $error !== null
            ? new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line'])
            : null;

        return new HttpRequestException(
            "HTTP request failed: {$method} {$url}{$detail}",
            $url,
            $method,
            null,
            $previous,
        );
    }

    private function assertNoHeaderInjection(string $url, string $method, string $name, string $value): void
    {
        if (preg_match('/[\r\n]/', $name) === 1 || preg_match('/[\r\n]/', $value) === 1) {
            throw new HttpRequestException(
                sprintf('Invalid request header "%s": CR/LF characters are not allowed (header injection).', $name),
                $url,
                $method,
            );
        }
    }

    /**
     * @param list<string> $rawHeaders
     */
    private function parseStatusCode(array $rawHeaders): int
    {
        foreach ($rawHeaders as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @param list<string> $rawHeaders
     * @return array<string, string>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $header) {
            if (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }
}
