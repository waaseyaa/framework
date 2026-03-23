<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\Session\NativeSession;

#[CoversClass(NativeSession::class)]
final class NativeSessionTest extends TestCase
{
    private NativeSession $session;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->session = new NativeSession();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    #[Test]
    public function setAndGetValue(): void
    {
        $this->session->set('key', 'value');
        $this->assertSame('value', $this->session->get('key'));
    }

    #[Test]
    public function getReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', $this->session->get('missing', 'fallback'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $this->session->set('exists', true);
        $this->assertTrue($this->session->has('exists'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->session->has('nope'));
    }

    #[Test]
    public function removeReturnsValueAndUnsets(): void
    {
        $this->session->set('temp', 42);
        $this->assertSame(42, $this->session->remove('temp'));
        $this->assertFalse($this->session->has('temp'));
    }

    #[Test]
    public function removeReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->session->remove('missing'));
    }

    #[Test]
    public function allReturnsSessionContents(): void
    {
        $this->session->set('a', 1);
        $this->session->set('b', 2);
        $this->assertSame(['a' => 1, 'b' => 2], $this->session->all());
    }

    #[Test]
    public function clearEmptiesSession(): void
    {
        $this->session->set('key', 'value');
        $this->session->clear();
        $this->assertSame([], $this->session->all());
    }

    #[Test]
    public function replaceMergesValues(): void
    {
        $this->session->set('keep', 'yes');
        $this->session->replace(['new' => 'val', 'keep' => 'updated']);
        $this->assertSame('updated', $this->session->get('keep'));
        $this->assertSame('val', $this->session->get('new'));
    }

    #[Test]
    public function getBagThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->session->getBag('attributes');
    }

    #[Test]
    public function getMetadataBagThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->session->getMetadataBag();
    }
}
