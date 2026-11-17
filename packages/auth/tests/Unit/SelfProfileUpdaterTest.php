<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Profile\SelfProfileUpdateException;
use Waaseyaa\Auth\Profile\SelfProfileUpdater;
use Waaseyaa\Auth\Tests\Support\PasswordChangeFixture;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

final class SelfProfileUpdaterTest extends TestCase
{
    private function fixture(bool $requireVerification = false, array $profileFields = [], bool $mailConfigured = false, bool $mailFails = false): array
    {
        [$repo, $manager, $fields, , $tokens, , $user, , $db] = new PasswordChangeFixture()->fixture();
        $user->setRawPassword('current-password');
        $user->setLegacyPassword(null);
        $user->setEmailVerified(true);
        $user->setTwoFactorSecret('JBSWY3DPEHPK3PXP');
        $repo->save($user);
        $config = AuthConfig::fromArray(['require_verified_email' => $requireVerification], 'testing');
        $actor = new AuthorizationPrincipal(1, true, [], [], 'self-profile-test');
        $scope = $this->createStub(AccountFieldReadScopeInterface::class);
        $scope->method('current')->willReturn($actor);
        $mailer = $this->createStub(AuthMailer::class);
        $mailer->method('isConfigured')->willReturn($mailConfigured);
        $deliveries = new \ArrayObject();
        $mailer->method('sendEmailVerification')->willReturnCallback(static function (User $user, string $token) use ($deliveries, $mailFails): void {
            $deliveries[] = $token;
            if ($mailFails) { throw new \RuntimeException('Synthetic transport failure'); }
        });
        $service = new SelfProfileUpdater($manager, $db, $tokens, $scope,
            new EntityAccessHandler([new UserAccessPolicy()]), $fields,
            new \Waaseyaa\Tests\Support\UserIdentityLookupFixture(),
            new AuthManager($fields, new VerifiedEmailAuthenticationEligibility($config, $fields)),
            new DatabaseRateLimiter($db), $config, $mailer, AuthExtensionRegistry::defaults(), $profileFields);
        return [$service, $actor, $repo, $tokens, $fields, $db, $deliveries];
    }

    public function testEmailChangeRevokesOldTokensAndSessionsWithoutChangingPasswordOrTwoFactor(): void
    {
        [$service, $actor, $repo, $tokens, $fields] = $this->fixture();
        $oldReset = $tokens->createToken(1, 'password_reset', 3600);
        $oldVerify = $tokens->createToken(1, 'email_verification', 3600);
        $before = $repo->find(1);
        $pass = $fields->credentials($before)->passwordHash;
        $twoFactor = $fields->twoFactor($before);
        $result = $service->save($actor, $before->mutationToken(), 'current-password', ['mail' => ' NEW@example.test ']);
        self::assertTrue($result->emailChanged);
        $fresh = $repo->find(1);
        self::assertSame('new@example.test', $fields->verification($fresh)->mail);
        self::assertFalse($fields->verification($fresh)->emailVerified);
        self::assertSame(1, $fields->sessionIdentity($fresh)->generation);
        self::assertSame($pass, $fields->credentials($fresh)->passwordHash);
        self::assertSame($twoFactor->secret, $fields->twoFactor($fresh)->secret);
        self::assertSame($twoFactor->recoveryCodeHashes, $fields->twoFactor($fresh)->recoveryCodeHashes);
        self::assertSame($twoFactor->lastUsedStep, $fields->twoFactor($fresh)->lastUsedStep);
        self::assertNull($tokens->validateToken($oldReset, 'password_reset'));
        self::assertNull($tokens->validateToken($oldVerify, 'email_verification'));
    }

    public function testUnchangedEmailPreservesVerificationAndRecoveryTokens(): void
    {
        [$service, $actor, $repo, $tokens, $fields] = $this->fixture();
        $token = $tokens->createToken(1, 'password_reset', 3600);
        $result = $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'test@example.invalid']);
        self::assertFalse($result->emailChanged);
        self::assertTrue($fields->verification($repo->find(1))->emailVerified);
        self::assertSame(0, $fields->sessionIdentity($repo->find(1))->generation);
        self::assertNotNull($tokens->validateToken($token, 'password_reset'));
    }

    public function testWrongPasswordCannotChangeEmailOrRevokeTokens(): void
    {
        [$service, $actor, $repo, $tokens, $fields] = $this->fixture();
        $token = $tokens->createToken(1, 'password_reset', 3600);
        try {
            $service->save($actor, $repo->find(1)->mutationToken(), 'wrong-password', ['mail' => 'new@example.test']);
            self::fail('Wrong password was accepted.');
        } catch (SelfProfileUpdateException $e) {
            self::assertSame('incorrect_password', $e->reason);
        }
        self::assertSame('test@example.invalid', $fields->verification($repo->find(1))->mail);
        self::assertNotNull($tokens->validateToken($token, 'password_reset'));
    }

    public function testAnotherPrincipalAndPrivilegeInjectionAreRefused(): void
    {
        [$service, $actor, $repo] = $this->fixture();
        foreach ([new AuthorizationPrincipal(2, true, [], [], 'other'), $actor] as $candidate) {
            try {
                $service->save($candidate, $repo->find(1)->mutationToken(), 'current-password', ['roles' => 'administrator']);
                self::fail('Forbidden mutation accepted.');
            } catch (SelfProfileUpdateException $e) {
                self::assertContains($e->reason, ['account_required', 'invalid_fields']);
            }
        }
    }

    public function testStaleExpectationCannotOverwriteAnInterveningChange(): void
    {
        [$service, $actor, $repo] = $this->fixture();
        $old = $repo->find(1)->mutationToken();
        $service->save($actor, $old, 'current-password', ['mail' => 'first@example.test']);
        $this->expectException(SelfProfileUpdateException::class);
        $this->expectExceptionMessage('profile_changed');
        $service->save($actor, $old, 'current-password', ['mail' => 'second@example.test']);
    }

    public function testVerifiedEmailPolicyRefusesUnconfiguredDeliveryBeforeChangingAnything(): void
    {
        [$service, $actor, $repo, , $fields] = $this->fixture(true);
        try {
            $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'new@example.test']);
            self::fail('Unconfigured verification was accepted.');
        } catch (SelfProfileUpdateException $e) {
            self::assertSame('verification_unavailable', $e->reason);
        }
        self::assertSame('test@example.invalid', $fields->verification($repo->find(1))->mail);
    }

    public function testDuplicateEmailRollsBackAndPreservesTokens(): void
    {
        [$service, $actor, $repo, $tokens, $fields] = $this->fixture();
        $other = new User(['name' => 'other', 'mail' => 'other@example.test']);
        $repo->save($other);
        $token = $tokens->createToken(1, 'password_reset', 3600);
        try {
            $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'other@example.test']);
            self::fail('Duplicate email accepted.');
        } catch (SelfProfileUpdateException $e) {
            self::assertSame('email_unavailable', $e->reason);
            self::assertSame('test@example.invalid', $fields->verification($repo->find(1))->mail);
            self::assertNotNull($tokens->validateToken($token, 'password_reset'));
        }
    }

    public function testNestedTransactionIsRefusedWithoutSavingOrMailing(): void
    {
        [$service, $actor, $repo, , $fields, $db] = $this->fixture();
        $outer = $db->transaction('caller-owned');
        try {
            $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'new@example.test']);
            self::fail('Nested operation accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('outermost', $error->getMessage());
        } finally {
            $outer->rollBack();
        }
        self::assertSame('test@example.invalid', $fields->verification($repo->find(1))->mail);
    }

    public function testLegacyUnboundEmailStillBelongsToItsExistingOwner(): void
    {
        [$service, $actor, $repo, , $fields, $db] = $this->fixture();
        $other = new User(['name' => 'legacy-other', 'mail' => 'other@example.test', 'status' => false]);
        $repo->save($other);
        // Test-only reproduction of a documented historical identity binding.
        $db->getConnection()->executeStatement("UPDATE user SET identity_mail_key=NULL WHERE uid=?", [$other->id()]);
        try {
            $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'other@example.test']);
            self::fail('Legacy address duplicated.');
        } catch (SelfProfileUpdateException $error) {
            self::assertSame('email_unavailable', $error->reason);
        }
        self::assertSame('test@example.invalid', $fields->verification($repo->find(1))->mail);
    }

    public function testPostCommitFailureReportsCommittedOutcomeAndKeepsRevocation(): void
    {
        [$service, $actor, $repo, $tokens, $fields] = $this->fixture();
        $token = $tokens->createToken(1, 'password_reset', 3600);
        $dispatcher = new \ReflectionProperty($repo, 'eventDispatcher')->getValue($repo);
        $dispatcher->addListener(\Waaseyaa\Entity\Event\EntityEvents::POST_SAVE->value, static function (): void {
            throw new \RuntimeException('Injected post-commit failure');
        });
        $result = $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'new@example.test']);
        self::assertTrue($result->emailChanged);
        self::assertTrue($result->completionEffectsFailed);
        self::assertSame('new@example.test', $fields->verification($repo->find(1))->mail);
        self::assertNull($tokens->validateToken($token, 'password_reset'));
        self::assertSame(1, $fields->sessionIdentity($repo->find(1))->generation);
    }

    public function testConfiguredProfileFieldsAreSavedThroughTheSameCanonicalWrite(): void
    {
        [$service, $actor, $repo, , , $db] = $this->fixture(profileFields: ['first_name', 'last_name']);
        $result = $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['first_name' => 'Updated', 'last_name' => 'Member']);
        self::assertFalse($result->emailChanged);
        self::assertSame('Updated', $db->getConnection()->fetchOne("SELECT json_extract(_data, '$.first_name') FROM user WHERE uid=1"));
        self::assertSame('Member', $db->getConnection()->fetchOne("SELECT json_extract(_data, '$.last_name') FROM user WHERE uid=1"));
    }

    public function testVerificationDeliverySuccessAndFailureBothReportTheCommittedSave(): void
    {
        foreach ([false, true] as $fails) {
            [$service, $actor, $repo, $tokens, $fields, , $deliveries] = $this->fixture(true, mailConfigured: true, mailFails: $fails);
            $result = $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', ['mail' => 'new@example.test']);
            self::assertTrue($result->emailChanged);
            self::assertTrue($result->verificationRequired);
            self::assertSame(!$fails, $result->verificationSent);
            self::assertSame('new@example.test', $fields->verification($repo->find(1))->mail);
            self::assertCount(1, $deliveries);
            self::assertNotNull($tokens->validateToken($deliveries[0], 'email_verification'));
        }
    }

    public function testReauthenticationAttemptsAreRateLimited(): void
    {
        [$service, $actor, $repo] = $this->fixture();
        for ($i = 0; $i < 6; ++$i) {
            try {
                $service->save($actor, $repo->find(1)->mutationToken(), 'wrong', ['mail' => 'new@example.test']);
                self::fail('Wrong password accepted.');
            } catch (SelfProfileUpdateException $error) {
                self::assertSame($i < 5 ? 'incorrect_password' : 'too_many_attempts', $error->reason);
            }
        }
    }

    public function testUnchangedMixedCaseMailAllowsNameEditWithoutRevocation(): void
    {
        [$service, $actor, $repo, $tokens, $fields, $db] = $this->fixture(profileFields: ['first_name']);
        $user = $repo->find(1);
        $user->set('mail', 'Test@example.invalid');
        $repo->save($user);
        $token = $tokens->createToken(1, 'password_reset', 3600);
        $result = $service->save($actor, $repo->find(1)->mutationToken(), 'current-password', [
            'mail' => 'Test@example.invalid', 'first_name' => 'Updated',
        ]);
        self::assertFalse($result->emailChanged);
        self::assertSame('Test@example.invalid', $fields->verification($repo->find(1))->mail);
        self::assertTrue($fields->verification($repo->find(1))->emailVerified);
        self::assertSame(0, $fields->sessionIdentity($repo->find(1))->generation);
        self::assertNotNull($tokens->validateToken($token, 'password_reset'));
        self::assertSame('Updated', $db->getConnection()->fetchOne("SELECT json_extract(_data, '$.first_name') FROM user WHERE uid=1"));
    }
}
