<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\ResponseSender;

#[CoversClass(ResponseSender::class)]
final class ResponseSenderTest extends TestCase
{
    #[Test]
    public function classIsFinal(): void
    {
        $ref = new \ReflectionClass(ResponseSender::class);
        $this->assertTrue($ref->isFinal());
    }

    #[Test]
    public function jsonMethodIsStaticAndReturnsNever(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'json');
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
        $this->assertSame('never', $method->getReturnType()?->getName());
    }

    #[Test]
    public function htmlMethodIsStaticAndReturnsNever(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'html');
        $this->assertTrue($method->isStatic());
        $this->assertTrue($method->isPublic());
        $this->assertSame('never', $method->getReturnType()?->getName());
    }

    #[Test]
    public function jsonAcceptsStatusDataAndHeaders(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'json');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('status', $params[0]->getName());
        $this->assertSame('data', $params[1]->getName());
        $this->assertSame('headers', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
    }

    #[Test]
    public function htmlAcceptsStatusHtmlAndHeaders(): void
    {
        $method = new \ReflectionMethod(ResponseSender::class, 'html');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('status', $params[0]->getName());
        $this->assertSame('html', $params[1]->getName());
        $this->assertSame('headers', $params[2]->getName());
        $this->assertTrue($params[2]->isDefaultValueAvailable());
    }
}
