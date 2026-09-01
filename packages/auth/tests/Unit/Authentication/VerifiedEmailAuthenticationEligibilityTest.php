<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Authentication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\Tests\Fixtures\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

#[CoversClass(VerifiedEmailAuthenticationEligibility::class)]
final class VerifiedEmailAuthenticationEligibilityTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function matrix(): iterable
    {
        yield 'policy off preserves active unverified login' => [false, true, false, true];
        yield 'policy on admits active verified login' => [true, true, true, true];
        yield 'policy on denies active unverified login' => [true, true, false, false];
        yield 'inactive verified account remains denied' => [true, false, true, false];
        yield 'inactive account is denied when policy is off' => [false, false, false, false];
    }

    #[Test]
    #[DataProvider('matrix')]
    public function it_applies_one_matrix_at_every_authentication_stage(
        bool $required,
        bool $active,
        bool $verified,
        bool $expected,
    ): void {
        $policy = new VerifiedEmailAuthenticationEligibility(
            new AuthConfig('open', $required, MailMissingPolicy::DevLog, 'test-secret', []),
            new \Waaseyaa\Tests\Support\UserInternalFieldReaderFixture(),
        );
        $user = new User([
            'uid' => 7,
            'status' => $active,
            'mail' => 'member@example.test',
            'email_verified' => $verified,
        ]);

        foreach (AuthenticationStage::cases() as $stage) {
            self::assertSame($expected, $policy->allows($user, $stage), $stage->value);
        }
    }
}

