<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface as SymfonyClient;

/** @internal Symfony networking adapted to Waaseyaa response semantics. */
final class SymfonyHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly float $timeout = 30.0,
        private readonly int $maxResponseBytes = 16777216,
        private readonly ?SymfonyClient $client = null,
    ) {
        if (!is_finite($timeout) || $timeout <= 0 || $maxResponseBytes < 1) {
            throw new \InvalidArgumentException('HTTP limits must be positive and finite.');
        }
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new HttpRequestException('Unsupported HTTP URL scheme.', $url, $method);
        }
        foreach ($headers as $name => $value) {
            if (strpbrk($name . $value, "\r\n\0") !== false) {
                throw new HttpRequestException('Invalid HTTP request header.', $url, $method);
            }
        }
        $client = $this->client ?? HttpClient::create();
        $response = null;
        try {
            $options = [
                'headers' => $headers,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
                'buffer' => false,
            ];
            if (is_array($body)) {
                $options['json'] = $body;
            } elseif ($body !== null) {
                $options['body'] = $body;
            }
            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            $content = '';
            foreach ($client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new \RuntimeException('HTTP transfer timed out.');
                }
                $bytes = $chunk->getContent();
                if (strlen($bytes) > $this->maxResponseBytes - strlen($content)) {
                    throw new \RuntimeException('HTTP response exceeded the configured maximum.');
                }
                $content .= $bytes;
            }
            $responseHeaders = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }

            return new HttpResponse($status, $content, $responseHeaders);
        } catch (\Throwable) {
            // Upstream diagnostics may contain credentials, URLs or response bytes.
            throw new HttpRequestException('HTTP transfer failed.', $url, $method);
        } finally {
            $response?->cancel();
        }
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}
