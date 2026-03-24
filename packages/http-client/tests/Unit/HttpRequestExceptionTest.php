<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\HttpResponse;

#[CoversClass(HttpRequestException::class)]
final class HttpRequestExceptionTest extends TestCase
{
    public function testConstructor(): void
    {
        $exception = new HttpRequestException(
            'Request failed',
            'https://example.com/api',
            'POST',
        );

        $this->assertSame('Request failed', $exception->getMessage());
        $this->assertSame('https://example.com/api', $exception->url);
        $this->assertSame('POST', $exception->method);
        $this->assertNull($exception->response);
    }

    public function testWithResponse(): void
    {
        $response = new HttpResponse(502, 'Bad Gateway');
        $exception = new HttpRequestException(
            'Server error',
            'https://example.com/api',
            'GET',
            $response,
        );

        $this->assertSame($response, $exception->response);
        $this->assertSame(502, $exception->response->statusCode);
    }

    public function testWithPreviousException(): void
    {
        $previous = new \RuntimeException('connection refused');
        $exception = new HttpRequestException(
            'Request failed',
            'https://example.com',
            'GET',
            null,
            $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }
}
