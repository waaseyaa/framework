<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Traits;

/**
 * Trait providing HTTP-style request helpers for API tests.
 *
 * Simulates API requests by building request arrays that can be
 * dispatched to a router or controller. This does not make real
 * HTTP calls; instead it builds structured request data that
 * test infrastructure can interpret.
 *
 * @api
 * @deprecated since 0.1.0-alpha.288. Build a real request in the higher-layer
 *   HTTP or protocol owner instead of modeling transport as an array.
 */
trait InteractsWithApi
{
    /**
     * Accumulated request headers.
     *
     * @var array<string, string>
     */
    private array $requestHeaders = [];

    /**
     * Simulate a GET request.
     *
     * @param string $uri The URI to request.
     * @param array<string, string> $headers Additional request headers.
     * @return array<string, mixed> A structured request descriptor.
     */
    protected function get(string $uri, array $headers = []): array
    {
        return $this->buildRequest('GET', $uri, headers: $headers);
    }

    /**
     * Simulate a POST request.
     *
     * @param string $uri The URI to request.
     * @param array<string, mixed> $body The request body data.
     * @param array<string, string> $headers Additional request headers.
     * @return array<string, mixed> A structured request descriptor.
     */
    protected function post(string $uri, array $body = [], array $headers = []): array
    {
        return $this->buildRequest('POST', $uri, $body, $headers);
    }

    /**
     * Simulate a PUT request.
     *
     * @param string $uri The URI to request.
     * @param array<string, mixed> $body The request body data.
     * @param array<string, string> $headers Additional request headers.
     * @return array<string, mixed> A structured request descriptor.
     */
    protected function put(string $uri, array $body = [], array $headers = []): array
    {
        return $this->buildRequest('PUT', $uri, $body, $headers);
    }

    /**
     * Simulate a PATCH request.
     *
     * @param string $uri The URI to request.
     * @param array<string, mixed> $body The request body data.
     * @param array<string, string> $headers Additional request headers.
     * @return array<string, mixed> A structured request descriptor.
     */
    protected function patch(string $uri, array $body = [], array $headers = []): array
    {
        return $this->buildRequest('PATCH', $uri, $body, $headers);
    }

    /**
     * Simulate a DELETE request.
     *
     * @param string $uri The URI to request.
     * @param array<string, string> $headers Additional request headers.
     * @return array<string, mixed> A structured request descriptor.
     */
    protected function delete(string $uri, array $headers = []): array
    {
        return $this->buildRequest('DELETE', $uri, headers: $headers);
    }

    /**
     * Set default headers for all subsequent requests.
     *
     * @param array<string, string> $headers
     * @return static
     */
    protected function withHeaders(array $headers): static
    {
        $this->requestHeaders = array_merge($this->requestHeaders, $headers);

        return $this;
    }

    /**
     * Set a single default header for subsequent requests.
     *
     * @return static
     */
    protected function withHeader(string $name, string $value): static
    {
        $this->requestHeaders[$name] = $value;

        return $this;
    }

    /**
     * Set an Authorization Bearer token header.
     *
     * @return static
     */
    protected function withToken(string $token): static
    {
        $this->requestHeaders['Authorization'] = 'Bearer ' . $token;

        return $this;
    }

    /**
     * Build a structured request descriptor.
     *
     * @param string $method HTTP method.
     * @param string $uri Request URI.
     * @param array<string, mixed> $body Request body data.
     * @param array<string, string> $headers Per-request headers (merged with defaults).
     * @return array<string, mixed>
     */
    private function buildRequest(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
    ): array {
        return [
            'method' => $method,
            'uri' => $uri,
            'body' => $body,
            'headers' => array_merge($this->requestHeaders, $headers),
        ];
    }
}
