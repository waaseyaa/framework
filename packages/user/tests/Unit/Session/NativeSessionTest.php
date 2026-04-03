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
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR']);
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

    #[Test]
    public function isSecureConnectionReturnsTrueWhenHttpsServerVarSet(): void
    {
        $_SERVER['HTTPS'] = 'on';
        self::assertTrue($this->session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionReturnsFalseWhenHttpsIsOff(): void
    {
        $_SERVER['HTTPS'] = 'off';
        self::assertFalse($this->session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionReturnsFalseOnPlainHttp(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        self::assertFalse($this->session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionIgnoresForwardedProtoWithoutTrustedProxies(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        self::assertFalse($this->session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionTrustsForwardedProtoFromTrustedProxy(): void
    {
        $session = new NativeSession(['10.0.0.1']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        self::assertTrue($session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionRejectsForwardedProtoFromUntrustedIp(): void
    {
        $session = new NativeSession(['10.0.0.1']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.99';
        self::assertFalse($session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionReturnsFalseWhenForwardedProtoIsHttp(): void
    {
        $session = new NativeSession(['10.0.0.1']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        self::assertFalse($session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionHandlesCaseInsensitiveForwardedProto(): void
    {
        $session = new NativeSession(['10.0.0.1']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'HTTPS';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        self::assertTrue($session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionReturnsFalseWhenRemoteAddrIsMissing(): void
    {
        $session = new NativeSession(['10.0.0.1']);
        unset($_SERVER['HTTPS'], $_SERVER['REMOTE_ADDR']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertFalse($session->isSecureConnection());
    }

    #[Test]
    public function isSecureConnectionRejectsCidrNotation(): void
    {
        // CIDR ranges are not supported; only exact IPs match
        $session = new NativeSession(['10.0.0.0/8']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        self::assertFalse($session->isSecureConnection());
    }

    #[Test]
    public function getCookieParamsReturnsArray(): void
    {
        $params = $this->session->getCookieParams();
        $this->assertIsArray($params);
        // session_get_cookie_params() always has these keys.
        $this->assertArrayHasKey('lifetime', $params);
        $this->assertArrayHasKey('path', $params);
        $this->assertArrayHasKey('httponly', $params);
    }
}
