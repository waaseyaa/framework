<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpResponse;

#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    public function testConstructor(): void
    {
        $response = new HttpResponse(200, '{"ok":true}', ['content-type' => 'application/json']);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame(['content-type' => 'application/json'], $response->headers);
    }

    public function testJson(): void
    {
        $response = new HttpResponse(200, '{"name":"test","count":42}');

        $this->assertSame(['name' => 'test', 'count' => 42], $response->json());
    }

    public function testJsonThrowsOnInvalidJson(): void
    {
        $response = new HttpResponse(200, 'not json');

        $this->expectException(\JsonException::class);
        $response->json();
    }

    public function testJsonReturnsEmptyArrayForScalar(): void
    {
        $response = new HttpResponse(200, '"just a string"');

        $this->assertSame([], $response->json());
    }

    public function testIsSuccessFor2xx(): void
    {
        $this->assertTrue((new HttpResponse(200, ''))->isSuccess());
        $this->assertTrue((new HttpResponse(201, ''))->isSuccess());
        $this->assertTrue((new HttpResponse(204, ''))->isSuccess());
        $this->assertTrue((new HttpResponse(299, ''))->isSuccess());
    }

    public function testIsSuccessReturnsFalseForNon2xx(): void
    {
        $this->assertFalse((new HttpResponse(301, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(400, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(404, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(500, ''))->isSuccess());
        $this->assertFalse((new HttpResponse(199, ''))->isSuccess());
    }
}
