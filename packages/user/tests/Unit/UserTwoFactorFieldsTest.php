<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

#[CoversClass(User::class)]
final class UserTwoFactorFieldsTest extends TestCase
{
    private UserInternalFieldReaderFixture $internal;

    protected function setUp(): void
    {
        $this->internal = new UserInternalFieldReaderFixture();
    }

    public function testTwoFactorSecretDefaultsToNull(): void
    {
        $user = new User();

        $this->assertNull($user->getTwoFactorSecret());
    }

    public function testTwoFactorRecoveryCodesDefaultToNull(): void
    {
        $user = new User();

        $this->assertNull($user->getTwoFactorRecoveryCodesHash());
    }

    public function testSetTwoFactorSecretRoundTrips(): void
    {
        $user = new User();

        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');

        $this->assertSame('JBSWY3DPEHPK3PXP', $this->internal->twoFactor($user)->secret);
    }

    public function testSetTwoFactorSecretAcceptsNull(): void
    {
        $user = new User();
        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');

        $user->setTwoFactorSecret(null);

        $this->assertNull($this->internal->twoFactor($user)->secret);
    }

    public function testSetTwoFactorRecoveryCodesRoundTrips(): void
    {
        $user = new User();
        $hashes = ['$argon2id$v=19$m=65536,t=4,p=1$a$b', '$argon2id$v=19$m=65536,t=4,p=1$c$d'];

        $user->setTwoFactorRecoveryCodesHash($hashes);

        $this->assertSame($hashes, $this->internal->twoFactor($user)->recoveryCodeHashes);
    }

    public function testSetTwoFactorRecoveryCodesAcceptsNull(): void
    {
        $user = new User();
        $user->setTwoFactorRecoveryCodesHash(['hash1', 'hash2']);

        $user->setTwoFactorRecoveryCodesHash(null);

        $this->assertSame([], $this->internal->twoFactor($user)->recoveryCodeHashes);
    }

    public function testRecoveryCodesGetterFiltersNonStringEntries(): void
    {
        $user = new User();

        // Set via the entity API directly to simulate corrupted/legacy data.
        $user->set('two_factor_recovery_codes_hash', ['valid', 123, null, 'also-valid']);

        $this->assertSame(['valid', 'also-valid'], $this->internal->twoFactor($user)->recoveryCodeHashes);
    }

    public function testTwoFactorFieldsCoexistWithExistingUserFields(): void
    {
        $user = User::make(['name' => 'alice', 'mail' => 'alice@example.com']);
        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFactorRecoveryCodesHash(['hash1']);

        $mail = $this->internal->mailDelivery($user);
        $this->assertSame('alice', $mail->name);
        $this->assertSame('alice@example.com', $mail->mail);

        $twoFactor = $this->internal->twoFactor($user);
        $this->assertSame('JBSWY3DPEHPK3PXP', $twoFactor->secret);
        $this->assertSame(['hash1'], $twoFactor->recoveryCodeHashes);
    }
}
