<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse;

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): HttpResponse;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse;
}
