<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Password\LegacyPasswordUpgrade;
use Waaseyaa\Auth\Password\LegacyPasswordVerifierChain;
use Waaseyaa\Auth\Password\PhpassPasswordVerifier;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\User\User;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Tests\Support\UserIdentityLookupFixture;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\Tests\Support\AuthenticationEligibilityFixture;

#[CoversClass(LoginController::class)]
final class LoginControllerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeEntityTypeManager(?EntityStorageInterface $storage = null): EntityTypeManager
    {
        $manager = $this->createStub(EntityTypeManager::class);

        if ($storage !== null) {
            $manager->method('getStorage')->willReturn($storage);
        }

        return $manager;
    }

    private function makeController(
        ?EntityTypeManager $entityTypeManager = null,
        ?RateLimiter $rateLimiter = null,
        ?TwoFactorService $twoFactor = null,
    ): LoginController {
        $entityTypeManager ??= $this->makeEntityTypeManager();

        return new LoginController(
            entityTypeManager: $entityTypeManager,
            rateLimiter: $rateLimiter ?? new RateLimiter(),
            twoFactor: $twoFactor ?? new TwoFactorService(new TwoFactorManager(), $entityTypeManager, new UserInternalFieldReaderFixture()),
            identityLookup: new UserIdentityLookupFixture(),
            internalFields: new UserInternalFieldReaderFixture(),
            eligibility: AuthenticationEligibilityFixture::policy(),
        );
    }

    private function makeRequest(array $body = [], string $ip = '127.0.0.1'): Request
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => $ip],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function returns_400_when_username_is_empty(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest(['username' => '', 'password' => 'secret']));

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
        $this->assertStringContainsString('username and password are required', $data['errors'][0]['detail']);
    }

    #[Test]
    public function returns_400_when_password_is_empty(): void
    {
        $controller = $this->makeController();

        $response = $controller($this->makeRequest(['username' => 'alice', 'password' => '']));

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
    }

    #[Test]
    public function returns_400_when_body_is_missing(): void
    {
        $controller = $this->makeController();

        $request = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            '',
        );

        $response = $controller($request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('400', $data['errors'][0]['status']);
    }

    #[Test]
    public function returns_429_when_rate_limited(): void
    {
        $rateLimiter = new RateLimiter();
        $ip = '127.0.0.1';
        $key = 'login:' . $ip;

        // Pre-fill 5 hits to exhaust the limit
        for ($i = 0; $i < 5; $i++) {
            $rateLimiter->hit($key, 60);
        }

        $controller = $this->makeController(rateLimiter: $rateLimiter);

        $response = $controller($this->makeRequest(['username' => 'alice', 'password' => 'secret'], $ip));

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('429', $data['errors'][0]['status']);
        $this->assertStringContainsString('Too many login attempts', $data['errors'][0]['detail']);
    }

    // ------------------------------------------------------------------
    // #2544 — legacy credential verification on the HTTP login path
    // ------------------------------------------------------------------

    /**
     * A migrated member's correct password must not be rejected as invalid
     * credentials. The request stops at the session guard (500) because a unit
     * test has no PHP session, and that is precisely the assertion: it got PAST
     * the 401, which is the branch #2544 changed.
     */
    #[Test]
    public function a_valid_legacy_credential_is_not_rejected_as_invalid(): void
    {
        [$entityTypeManager, $upgrade] = $this->migratedRosterController();
        $controller = $this->makeLegacyController($entityTypeManager, $upgrade);

        $response = $controller($this->makeRequest([
            'username' => 'migrated.member',
            'password' => 'the password they already have',
        ]));

        $this->assertNotSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function successful_login_issues_a_generation_bound_session(): void
    {
        session_save_path(sys_get_temp_dir());
        self::assertTrue(session_start());
        [$entityTypeManager, $upgrade] = $this->migratedRosterController();
        $controller = $this->makeLegacyController($entityTypeManager, $upgrade);

        $response = $controller($this->makeRequest([
            'username' => 'migrated.member',
            'password' => 'the password they already have',
        ]));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($document['data']['emailVerified']);
        self::assertArrayHasKey('waaseyaa_uid', $_SESSION);
        self::assertSame(0, $_SESSION['waaseyaa_session_generation']);
        session_write_close();
    }

    #[Test]
    public function a_wrong_password_against_a_legacy_credential_is_still_a_401(): void
    {
        [$entityTypeManager, $upgrade] = $this->migratedRosterController();
        $controller = $this->makeLegacyController($entityTypeManager, $upgrade);

        $response = $controller($this->makeRequest([
            'username' => 'migrated.member',
            'password' => 'not the password',
        ]));

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('Invalid credentials.', $data['errors'][0]['detail']);
    }

    /**
     * Without the collaborator the controller keeps its historical native-only
     * check, so the same legacy credential is a plain 401 — the opt-in boundary.
     */
    #[Test]
    public function without_the_upgrade_a_legacy_credential_is_a_401(): void
    {
        [$entityTypeManager] = $this->migratedRosterController();
        $controller = $this->makeLegacyController($entityTypeManager, null);

        $response = $controller($this->makeRequest([
            'username' => 'migrated.member',
            'password' => 'the password they already have',
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

    private function makeLegacyController(
        EntityTypeManager $entityTypeManager,
        ?LegacyPasswordUpgrade $upgrade,
        bool $requireVerifiedEmail = false,
    ): LoginController {
        return new LoginController(
            entityTypeManager: $entityTypeManager,
            rateLimiter: new RateLimiter(),
            twoFactor: new TwoFactorService(new TwoFactorManager(), $entityTypeManager, new UserInternalFieldReaderFixture()),
            identityLookup: new UserIdentityLookupFixture(),
            internalFields: new UserInternalFieldReaderFixture(),
            eligibility: AuthenticationEligibilityFixture::policy($requireVerifiedEmail),
            extensions: null,
            passwords: $upgrade,
        );
    }

    #[Test]
    public function required_verification_denial_does_not_mutate_an_existing_identity(): void
    {
        $_SESSION = [
            'waaseyaa_uid' => 99,
            'waaseyaa_session_generation' => 0,
            'waaseyaa_pending_2fa_uid' => 99,
            'unrelated' => 'preserved',
        ];
        [$entityTypeManager, $upgrade] = $this->migratedRosterController(twoFactor: true);
        $controller = $this->makeLegacyController($entityTypeManager, $upgrade, requireVerifiedEmail: true);

        $response = $controller($this->makeRequest([
            'username' => 'migrated.member',
            'password' => 'the password they already have',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Invalid credentials.', json_decode((string) $response->getContent(), true)['errors'][0]['detail']);
        self::assertSame([
            'waaseyaa_uid' => 99,
            'waaseyaa_session_generation' => 0,
            'waaseyaa_pending_2fa_uid' => 99,
            'unrelated' => 'preserved',
        ], $_SESSION);
    }

    /** @return array{EntityTypeManager, LegacyPasswordUpgrade} */
    private function migratedRosterController(bool $twoFactor = false): array
    {
        $database = DBALDatabase::createSqlite();
        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        new SqlSchemaHandler($userType, $database)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userType,
            new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
            new EventDispatcher(),
            database: $database,
        );
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $entityTypeManager->registerEntityType($userType);

        $user = new User([
            'uid' => 1,
            'uuid' => 'u-1',
            'name' => 'migrated.member',
            'mail' => 'member@example.test',
            'status' => true,
            'pass' => '',
            'legacy_pass' => self::phpassHash('the password they already have', 'aBcD1234'),
            'two_factor_secret' => $twoFactor ? 'JBSWY3DPEHPK3PXP' : null,
            'two_factor_recovery_codes_hash' => $twoFactor ? [] : null,
        ]);
        $user->enforceIsNew();
        $repository->save($user);

        return [
            $entityTypeManager,
            new LegacyPasswordUpgrade(
                $entityTypeManager,
                new LegacyPasswordVerifierChain(new PhpassPasswordVerifier()),
            ),
        ];
    }

    /** Openwall's reference hashing routine — see PhpassPasswordVerifierTest. */
    private static function phpassHash(string $password, string $salt): string
    {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $digest = md5($salt . $password, true);
        $rounds = 1 << 13;
        do {
            $digest = md5($digest . $password, true);
        } while (--$rounds);

        $output = '';
        $i = 0;
        do {
            $value = \ord($digest[$i++]);
            $output .= $itoa64[$value & 0x3F];
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 8;
            }
            $output .= $itoa64[($value >> 6) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            if ($i < 16) {
                $value |= \ord($digest[$i]) << 16;
            }
            $output .= $itoa64[($value >> 12) & 0x3F];
            if ($i++ >= 16) {
                break;
            }
            $output .= $itoa64[($value >> 18) & 0x3F];
        } while ($i < 16);

        return '$P$B' . $salt . $output;
    }
}
