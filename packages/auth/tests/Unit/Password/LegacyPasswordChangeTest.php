<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Password;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Controller\ResetPasswordController;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\Password\LegacyPasswordChange;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\Password\LegacyPasswordVerifierChain;
use Waaseyaa\Auth\Password\WordPressBcryptPasswordVerifier;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\User;

final class LegacyPasswordChangeTest extends TestCase
{
    private function fixture(): array
    {
        return new \Waaseyaa\Auth\Tests\Support\PasswordChangeFixture()->fixture();
    }

    public function testProofRequiresDifferentPasswordAndNeverAuthenticatesUntilAtomicCompletion(): void
    {
        [$repo, $manager, $fields, $config, $tokens, $change, $user, $chain] = $this->fixture();
        $gate = new VerifiedEmailAuthenticationEligibility($config, $fields);
        foreach (AuthenticationStage::cases() as $stage) {
            self::assertFalse($gate->allows($user, $stage));
        }
        self::assertFalse(new LegacyPasswordUpgrade($manager, $chain, requireChange: true)->verify($user, 'old-password', $fields->credentials($user)));
        self::assertNull($change->challenge($user, 'wrong-password'));
        $token = $change->challenge($user, 'old-password');
        self::assertNotNull($token);
        self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
        $data = $tokens->validateToken($token, 'password_reset');
        self::assertSame('different_password_required', $change->complete($repo, $data, 'old-password'));
        self::assertNotNull($tokens->validateToken($token, 'password_reset'), 'Rejected change rolls back token consumption.');
        self::assertNull($change->complete($repo, $data, 'new-password'));
        $fresh = $repo->find(1);
        self::assertTrue(password_verify('new-password', $fields->credentials($fresh)->passwordHash));
        self::assertNull($fields->credentials($fresh)->legacyPasswordHash);
        self::assertSame(1, $fields->sessionIdentity($fresh)->generation);
        foreach (AuthenticationStage::cases() as $stage) {
            self::assertTrue($gate->allows($fresh, $stage));
        }
        self::assertSame('invalid_or_expired_token', $change->complete($repo, $data, 'attacker-password'));
        self::assertNull($change->challenge($fresh, 'old-password'));
    }

    public function testEmailFallbackAlsoRequiresDifferentPasswordAndDisabledAccountCannotComplete(): void
    {
        [$repo, , , , $tokens, $change, $user] = $this->fixture();
        $token = $tokens->createToken(1, 'password_reset', 300);
        $data = $tokens->validateToken($token, 'password_reset');
        self::assertSame('different_password_required', $change->complete($repo, $data, 'old-password'));
        $user->set('status', false);
        $repo->save($user);
        self::assertNull($change->challenge($user, 'old-password'));
        self::assertSame('invalid_or_expired_token', $change->complete($repo, $data, 'new-password'));
    }

    public function testChangedCredentialsInvalidateOutstandingProof(): void
    {
        [$repo, , , , $tokens, $change, $user] = $this->fixture();
        $token = $change->challenge($user, 'old-password');
        $data = $tokens->validateToken($token, 'password_reset');
        $user->setRawPassword('operator-set-password');
        $repo->save($user);
        self::assertSame('invalid_or_expired_token', $change->complete($repo, $data, 'attacker-password'));
    }

    public function testWordpressBcryptRejectsUnboundedWorkAndWrongBytes(): void
    {
        $verifier = new WordPressBcryptPasswordVerifier();
        $hash = '$wp'.password_hash(base64_encode(hash_hmac('sha384', 'dollar$ and unicode ᓇ', 'wp-sha384', true)), PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertTrue($verifier->verify('dollar$ and unicode ᓇ', $hash));
        self::assertFalse($verifier->verify('wrong', $hash));
        self::assertFalse($verifier->supports(str_replace('$04$', '$31$', $hash)));
        self::assertFalse($verifier->verify(str_repeat('x', 4097), $hash));
        self::assertFalse($verifier->supports(substr($hash, 3)));
    }

    public static function importedFormats(): iterable
    {
        yield 'WordPress bcrypt' => [false];
        yield 'WordPress phpass' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('importedFormats')]
    public function testCanonicalJsonLoginIssuesOnlyLimitedProofAndResetCompletesIt(bool $portable): void
    {
        [$repo, $manager, $fields, $config, $tokens, $change, $user, $chain, $db] = new \Waaseyaa\Auth\Tests\Support\PasswordChangeFixture()->fixture($portable);
        $login = new LoginController($manager, new DatabaseRateLimiter($db),
            new TwoFactorService(new \Waaseyaa\Auth\TwoFactorManager(), $manager, $fields),
            new \Waaseyaa\Tests\Support\UserIdentityLookupFixture(), $fields,
            new VerifiedEmailAuthenticationEligibility($config, $fields),
            passwords: new LegacyPasswordUpgrade($manager, $chain, requireChange: true), passwordChange: $change);
        $request = static fn(array $body): Request => Request::create('/api/auth/login', 'POST',
            server: ['REMOTE_ADDR' => '192.0.2.24'], content: json_encode($body));
        self::assertSame(401, $login($request(['username' => 'legacy', 'password' => 'wrong']))->getStatusCode());
        $_SESSION = ['waaseyaa_pending_2fa_uid' => 1];
        $result = $login($request(['username' => 'legacy', 'password' => 'old-password']));
        self::assertSame(200, $result->getStatusCode());
        self::assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        $body = json_decode($result->getContent(), true);
        self::assertSame('password_change_required', $body['data']['attributes']['state']);
        self::assertArrayNotHasKey('waaseyaa_pending_2fa_uid', $_SESSION);
        self::assertArrayNotHasKey(\Waaseyaa\User\Session\AuthenticatedSession::USER_ID_KEY, $_SESSION);
        $reset = new ResetPasswordController($manager, $tokens, $change);
        $token = $body['data']['attributes']['token'];
        self::assertSame(422, $reset($request(['token' => $token, 'password' => 'old-password', 'password_confirmation' => 'old-password']))->getStatusCode());
        self::assertSame(200, $reset($request(['token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password']))->getStatusCode());
        self::assertSame(422, $reset($request(['token' => $token, 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password']))->getStatusCode());
        self::assertSame(401, $login($request(['username' => 'legacy', 'password' => 'old-password']))->getStatusCode());
        self::assertTrue(password_verify('new-password', $fields->credentials($repo->find(1))->passwordHash));
    }

    public function testMismatchedStoreCannotMutateCredentialsOrConsumeToken(): void
    {
        [$repo, , $fields, $config, $tokens, $change, $user, $chain] = $this->fixture();
        $token = $change->challenge($user, 'old-password');
        $other = new LegacyPasswordChange($config, $chain, $fields, $tokens, DBALDatabase::createSqlite());
        try {
            $other->complete($repo, $tokens->validateToken($token, 'password_reset'), 'new-password');
            self::fail('Mismatched store admitted.');
        } catch (\LogicException) {
            self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
            self::assertNotNull($tokens->validateToken($token, 'password_reset'));
        }
    }

    public function testFailureAfterCredentialSaveRollsBackCredentialsAndTokenConsumption(): void
    {
        [$repo, , $fields, , $tokens, $change, $user, , $db] = $this->fixture();
        $token = $change->challenge($user, 'old-password');
        $db->getConnection()->executeStatement("CREATE TRIGGER fail_revoke BEFORE DELETE ON auth_tokens BEGIN SELECT RAISE(ABORT, 'synthetic revoke failure'); END");
        try {
            $change->complete($repo, $tokens->validateToken($token, 'password_reset'), 'new-password');
            self::fail('Expected revoke failure.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
            self::assertNotNull($tokens->validateToken($token, 'password_reset'));
        }
    }

    public function testExpiredProofCannotCompleteEvenWhenPrevalidationWasLive(): void
    {
        [$repo, , $fields, $config, , , $user, $chain, $db] = $this->fixture();
        $clock = new class implements \Waaseyaa\Entity\DateTime\EntityClockInterface {
            public int $seconds = 0;
            public function now(): \DateTimeImmutable { return new \DateTimeImmutable('2026-11-03 12:00:00 UTC')->modify('+'.$this->seconds.' seconds'); }
        };
        $tokens = new AuthTokenRepository($db, 'synthetic-expiry-secret', $clock);
        $change = new LegacyPasswordChange($config, $chain, $fields, $tokens, $db);
        $token = $change->challenge($user, 'old-password');
        $data = $tokens->validateToken($token, 'password_reset');
        $clock->seconds = 301;
        self::assertNull($tokens->validateToken($token, 'password_reset'));
        self::assertSame('invalid_or_expired_token', $change->complete($repo, $data, 'new-password'));
        self::assertSame('', $fields->credentials($repo->find(1))->passwordHash);
    }

    public function testDefaultModeKeepsNativeLoginAndProvidesAtomicRecovery(): void
    {
        [$repo, $manager, $fields, , $tokens, , $user, $chain, $db] = $this->fixture();
        $config = AuthConfig::fromArray([], 'test');
        $change = new LegacyPasswordChange($config, $chain, $fields, $tokens, $db);
        self::assertNull($change->challenge($user, 'old-password'));
        self::assertTrue(new LegacyPasswordUpgrade($manager, $chain)->verify($user, 'old-password', $fields->credentials($user)));
        $fresh = $repo->find(1);
        self::assertTrue(new VerifiedEmailAuthenticationEligibility($config, $fields)->allows($fresh, AuthenticationStage::PasswordLogin));
        $token = $tokens->createToken(1, 'password_reset', 300);
        self::assertNull($change->complete($repo, $tokens->validateToken($token, 'password_reset'), 'new-password'));
        self::assertTrue(password_verify('new-password', $fields->credentials($repo->find(1))->passwordHash));
    }

    public function testProviderBindsRequiredChangeAndCanonicalDefaultRecovery(): void
    {
        foreach ([false, true] as $required) {
            [$repo, $manager, $fields, , , , $user, , $db] = $this->fixture();
            $provider = new \Waaseyaa\Auth\AuthServiceProvider();
            new \ReflectionProperty(\Waaseyaa\Foundation\ServiceProvider\ServiceProvider::class, 'config')->setValue($provider, [
                'auth' => ['token_secret' => bin2hex(random_bytes(32)),
                    'legacy_passwords' => ['formats' => ['phpass', 'wordpress_bcrypt'], 'require_change' => $required]],
            ]);
            $provider->setKernelServices(new class ($manager, $fields, $db) implements \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface {
                public function __construct(private $manager, private $fields, private $db) {}
                public function get(string $abstract): ?object
                {
                    return match ($abstract) {
                        \Waaseyaa\Entity\EntityTypeManagerInterface::class => $this->manager,
                        \Waaseyaa\Access\User\UserInternalFieldReaderInterface::class => $this->fields,
                        \Waaseyaa\Database\DatabaseInterface::class => $this->db,
                        default => null,
                    };
                }
            });
            $provider->register();
            $service = new \ReflectionMethod($provider, 'resolve')->invoke($provider, LegacyPasswordChange::class);
            self::assertSame($required, $service->challenge($user, 'old-password') !== null);
            $upgrader = new \ReflectionMethod($provider, 'resolve')->invoke($provider, LegacyPasswordUpgrade::class);
            self::assertSame(!$required, $upgrader->verify($user, 'old-password', $fields->credentials($user)));
        }
    }
}
