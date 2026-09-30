<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\SymfonyHttpClient;

#[CoversClass(SymfonyHttpClient::class)]
final class SymfonyHttpClientTest extends TestCase
{
    public function test_invalid_headers_and_scheme_never_reach_network(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse();
        });
        $client = new SymfonyHttpClient(client: $mock);
        foreach ([['file:///a', []], ['http://example.test/', ['Header' => "bad\r\nInjected: yes"]], ['http://example.test/', ['Header' => "bad\0value"]]] as [$url, $headers]) {
            try {
                $client->get($url, $headers);
                self::fail('Invalid input must refuse.');
            } catch (HttpRequestException) {
                self::assertSame(0, $calls);
            }
        }
    }

    public function test_normal_operation_budgets_remain_distinct(): void
    {
        foreach ([15.0, 20.0] as $budget) {
            $mock = new MockHttpClient(static function (string $method, string $url, array $options) use ($budget): MockResponse {
                self::assertSame($budget, $options['max_duration']);
                self::assertSame($budget, $options['timeout']);

                return new MockResponse('{}');
            });
            self::assertSame('{}', new SymfonyHttpClient($budget, 1048576, $mock)->post('http://example.test/')->body);
        }
    }

    public function test_options_preserve_total_budget_tls_and_single_attempt(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            ++$calls;
            self::assertSame('POST', $method);
            self::assertSame(2.0, $options['max_duration']);
            self::assertSame(2.0, $options['timeout']);
            self::assertSame(0, $options['max_redirects']);
            self::assertTrue($options['verify_peer']);
            self::assertTrue($options['verify_host']);
            self::assertFalse($options['buffer']);
            self::assertSame('{"input":"text"}', $options['body']);

            return new MockResponse('unavailable', ['http_code' => 503, 'response_headers' => ['x-test: value']]);
        });
        $response = new SymfonyHttpClient(2.0, 100, $mock)->post('https://example.test/', [], ['input' => 'text']);
        self::assertSame(503, $response->statusCode);
        self::assertSame('unavailable', $response->body);
        self::assertSame('value', $response->headers['x-test']);
        self::assertSame(1, $calls);
    }

    public function test_exact_body_ceiling_succeeds_and_overflow_refuses(): void
    {
        $response = new SymfonyHttpClient(2, 3, new MockHttpClient(new MockResponse('abc')))->get('http://example.test/');
        self::assertSame('abc', $response->body);
        $this->expectException(HttpRequestException::class);
        new SymfonyHttpClient(2, 3, new MockHttpClient(new MockResponse('abcd')))->get('http://example.test/');
    }

    public function test_upstream_error_details_are_not_exposed(): void
    {
        $mock = new MockHttpClient(static function (): never {
            throw new \RuntimeException('synthetic-secret');
        });
        try {
            new SymfonyHttpClient(2, 3, $mock)->get('http://example.test/');
            self::fail('Failure must propagate.');
        } catch (HttpRequestException $error) {
            self::assertSame('HTTP transfer failed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }
}
