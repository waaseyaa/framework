<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\TwoFactorManager;

#[CoversClass(TwoFactorManager::class)]
final class TwoFactorManagerTest extends TestCase
{
    private TwoFactorManager $twoFactor;

    protected function setUp(): void
    {
        $this->twoFactor = new TwoFactorManager();
    }

    public function testGenerateSecretReturns32CharBase32String(): void
    {
        $secret = $this->twoFactor->generateSecret();

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGenerateSecretIsUnique(): void
    {
        $secret1 = $this->twoFactor->generateSecret();
        $secret2 = $this->twoFactor->generateSecret();

        $this->assertNotSame($secret1, $secret2);
    }

    public function testVerifyCodeAcceptsValidCode(): void
    {
        $secret = $this->twoFactor->generateSecret();
        $code = $this->twoFactor->getCurrentCode($secret);

        $this->assertTrue($this->twoFactor->verifyCode($secret, $code));
    }

    public function testVerifyCodeRejectsInvalidCode(): void
    {
        $secret = $this->twoFactor->generateSecret();

        $this->assertFalse($this->twoFactor->verifyCode($secret, '000000'));
    }

    public function testVerifyCodeRejectsEmptyCode(): void
    {
        $secret = $this->twoFactor->generateSecret();

        $this->assertFalse($this->twoFactor->verifyCode($secret, ''));
    }

    public function testGetQrCodeUriReturnsOtpauthUri(): void
    {
        $uri = $this->twoFactor->getQrCodeUri(
            secret: 'JBSWY3DPEHPK3PXP',
            email: 'alice@test.com',
            issuer: 'GoFormX',
        );

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=GoFormX', $uri);
        $this->assertStringContainsString('alice%40test.com', $uri);
    }

    public function testGenerateRecoveryCodesReturnsEightCodes(): void
    {
        $codes = $this->twoFactor->generateRecoveryCodes();

        $this->assertCount(8, $codes);
    }

    public function testRecoveryCodesAreUnique(): void
    {
        $codes = $this->twoFactor->generateRecoveryCodes();

        $this->assertCount(8, array_unique($codes));
    }

    public function testRecoveryCodesMatchExpectedFormat(): void
    {
        $codes = $this->twoFactor->generateRecoveryCodes();

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}$/', $code);
        }
    }

}
