<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use Waaseyaa\SSR\SsrResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SsrResponse::class)]
final class SsrResponseTest extends TestCase
{
    #[Test]
    public function constructionWithDefaults(): void
    {
        $response = new SsrResponse(content: '<h1>Hello</h1>');

        $this->assertSame('<h1>Hello</h1>', $response->content);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['Content-Type' => 'text/html; charset=UTF-8'], $response->headers);
    }

    #[Test]
    public function constructionWithCustomStatusAndHeaders(): void
    {
        $headers = ['Content-Type' => 'text/plain', 'X-Custom' => 'value'];
        $response = new SsrResponse(
            content: 'Not Found',
            statusCode: 404,
            headers: $headers,
        );

        $this->assertSame('Not Found', $response->content);
        $this->assertSame(404, $response->statusCode);
        $this->assertSame($headers, $response->headers);
    }

    #[Test]
    public function redirect_sets_status_and_location(): void
    {
        $response = SsrResponse::redirect('/admin/today?chat=open');

        $this->assertSame('', $response->content);
        $this->assertSame(302, $response->statusCode);
        $this->assertSame(['Location' => '/admin/today?chat=open'], $response->headers);
    }

    #[Test]
    public function redirect_accepts_custom_status(): void
    {
        $response = SsrResponse::redirect('/elsewhere', 301);

        $this->assertSame(301, $response->statusCode);
        $this->assertSame('/elsewhere', $response->headers['Location']);
    }

    #[Test]
    public function propertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(SsrResponse::class);

        $this->assertTrue($reflection->getProperty('content')->isReadOnly());
        $this->assertTrue($reflection->getProperty('statusCode')->isReadOnly());
        $this->assertTrue($reflection->getProperty('headers')->isReadOnly());
    }
}
