<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient;

final class StreamHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly float $timeout = 30.0,
    ) {}

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $httpHeaders = [];
        foreach ($headers as $name => $value) {
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

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $httpHeaders),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        if ($content !== null) {
            $options['http']['content'] = $content;
        }

        $context = stream_context_create($options);
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            throw new HttpRequestException(
                "HTTP request failed: {$method} {$url}",
                $url,
                $method,
            );
        }

        $statusCode = $this->parseStatusCode($http_response_header ?? []);
        $responseHeaders = $this->parseHeaders($http_response_header ?? []);

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
