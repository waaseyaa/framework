<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseTwoFactor;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\DisableTwoFactorController;
use Waaseyaa\Auth\Controller\EnableTwoFactorController;
use Waaseyaa\Auth\Controller\SetupTwoFactorController;
use Waaseyaa\Auth\Controller\VerifyTwoFactorController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Testing\StorageBackedStubRepository;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

/**
 * Pipeline-level E2E for the 2FA subsystem. Wires the four controllers +
 * service + a stateful in-memory User storage, then walks through the
 * spec scenarios (setup → enable → verify TOTP, recovery-code consumption,
 * disable). Session promotion in the pending login path is exercised via
 * the $_SESSION superglobal directly.
 *
 * Distinct from the per-controller unit tests in packages/auth/tests/Unit/Controller/:
 * those isolate each controller; this integration test exercises the
 * full chain end to end, including session-state transitions.
 */
#[CoversNothing]
final class TwoFactorE2ETest extends TestCase
{
    private User $user;
    private TwoFactorService $service;
    private TwoFactorManager $manager;
    private EntityTypeManagerInterface $entityTypeManager;
    private RateLimiter $rateLimiter;

    private SetupTwoFactorController $setupCtrl;
    private EnableTwoFactorController $enableCtrl;
    private VerifyTwoFactorController $verifyCtrl;
    private DisableTwoFactorController $disableCtrl;

    protected function setUp(): void
    {
        $this->user = User::make(['uid' => 42, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $this->user->setRawPassword('correct-horse-battery-staple');
        $this->manager = new TwoFactorManager();
        $this->entityTypeManager = $this->makeEntityTypeManager([42 => $this->user]);
        $this->service = new TwoFactorService($this->manager, $this->entityTypeManager, new UserInternalFieldReaderFixture());
        $this->rateLimiter = new RateLimiter();
        $this->setupCtrl = new SetupTwoFactorController($this->service);
        $this->enableCtrl = new EnableTwoFactorController($this->service);
        $this->verifyCtrl = new VerifyTwoFactorController(
            $this->service,
            $this->rateLimiter,
            $this->entityTypeManager,
            new UserInternalFieldReaderFixture(),
        );
        $this->disableCtrl = new DisableTwoFactorController($this->service);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTotpFlowEnableLoginVerifySucceeds(): void
    {
        // 1. Setup
        $setupRes = ($this->setupCtrl)($this->authenticatedRequest($this->user));
        $this->assertSame(200, $setupRes->getStatusCode());
        $setup = json_decode($setupRes->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['attributes'];

        // 2. Enable with first TOTP
        $firstCode = $this->manager->getCurrentCode($setup['secret']);
        $enableRes = ($this->enableCtrl)($this->authenticatedRequest($this->user, [
            'secret' => $setup['secret'],
            'recovery_codes' => $setup['recovery_codes'],
            'first_code' => $firstCode,
        ]));
        $this->assertSame(200, $enableRes->getStatusCode());
        $this->assertSame($setup['secret'], new UserInternalFieldReaderFixture()->twoFactor($this->user)->secret);

        // 3. Simulate pending-login session (LoginController would set this)
        $_SESSION['waaseyaa_pending_2fa_uid'] = $this->user->id();

        // 4. Verify with current TOTP completes login
        $currentTotp = $this->manager->getCurrentCode($setup['secret']);
        $verifyRes = ($this->verifyCtrl)($this->pendingRequest(['code' => $currentTotp]));
        $this->assertSame(200, $verifyRes->getStatusCode());
        $verifyBody = json_decode($verifyRes->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($verifyBody['data']['attributes']['verified']);

        // Session must be promoted
        $this->assertSame($this->user->id(), $_SESSION[AuthenticatedSession::USER_ID_KEY] ?? null);
        $this->assertSame(0, $_SESSION[AuthenticatedSession::GENERATION_KEY] ?? null);
        $this->assertArrayNotHasKey('waaseyaa_pending_2fa_uid', $_SESSION);
    }

    public function testRecoveryFlowConsumesCodeOnUse(): void
    {
        // Setup + enable.
        $setup = $this->setupAndEnable();
        $recoveryCode = $setup['recovery_codes'][0];

        // Simulate pending-login session.
        $_SESSION['waaseyaa_pending_2fa_uid'] = $this->user->id();

        // First use of recovery code: succeeds.
        $first = ($this->verifyCtrl)($this->pendingRequest(['code' => $recoveryCode]));
        $this->assertSame(200, $first->getStatusCode());

        // Same code must NOT work again — reset pending and try.
        $_SESSION = [];
        $_SESSION['waaseyaa_pending_2fa_uid'] = $this->user->id();
        $second = ($this->verifyCtrl)($this->pendingRequest(['code' => $recoveryCode]));
        $this->assertSame(401, $second->getStatusCode());

        // 7 of 8 codes remain.
        $remaining = new UserInternalFieldReaderFixture()->twoFactor($this->user)->recoveryCodeHashes;
        $this->assertNotNull($remaining);
        $this->assertCount(7, $remaining);
    }

    public function testDisableFlowWipesCredentials(): void
    {
        $setup = $this->setupAndEnable();

        // Disable with valid TOTP.
        $code = $this->manager->getCurrentCode($setup['secret']);
        $disableRes = ($this->disableCtrl)($this->authenticatedRequest($this->user, ['code' => $code]));
        $this->assertSame(200, $disableRes->getStatusCode());
        $twoFactor = new UserInternalFieldReaderFixture()->twoFactor($this->user);
        $this->assertNull($twoFactor->secret);
        $this->assertSame([], $twoFactor->recoveryCodeHashes);
        $this->assertFalse($this->service->isEnabled($this->user));
    }

    public function testDisableRequiresProofOfPossession(): void
    {
        $this->setupAndEnable();

        $res = ($this->disableCtrl)($this->authenticatedRequest($this->user, ['code' => '000000']));
        $this->assertSame(401, $res->getStatusCode());
        // Credentials NOT wiped.
        $this->assertNotNull(new UserInternalFieldReaderFixture()->twoFactor($this->user)->secret);
    }

    public function testVerifyRateLimitsRepeatedFailures(): void
    {
        $this->setupAndEnable();
        $_SESSION['waaseyaa_pending_2fa_uid'] = $this->user->id();

        // 5 wrong codes — each one a 401 with budget consumed.
        for ($i = 0; $i < 5; $i++) {
            $res = ($this->verifyCtrl)($this->pendingRequest(['code' => '000000']));
            $this->assertSame(401, $res->getStatusCode());
        }

        // 6th attempt: 429.
        $res = ($this->verifyCtrl)($this->pendingRequest(['code' => '000000']));
        $this->assertSame(429, $res->getStatusCode());
        $this->assertSame('60', $res->headers->get('Retry-After'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{secret: string, qr_code_uri: string, recovery_codes: list<string>}
     */
    private function setupAndEnable(): array
    {
        $setupRes = ($this->setupCtrl)($this->authenticatedRequest($this->user));
        $setup = json_decode($setupRes->getContent(), true, 512, JSON_THROW_ON_ERROR)['data']['attributes'];
        $firstCode = $this->manager->getCurrentCode($setup['secret']);
        ($this->enableCtrl)($this->authenticatedRequest($this->user, [
            'secret' => $setup['secret'],
            'recovery_codes' => $setup['recovery_codes'],
            'first_code' => $firstCode,
        ]));
        return $setup;
    }

    /** @param array<string,mixed> $body */
    private function authenticatedRequest(User $user, array $body = []): Request
    {
        $request = Request::create('/api/auth/2fa/x', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->attributes->set('_account', $user);
        return $request;
    }

    /** @param array<string,mixed> $body */
    private function pendingRequest(array $body = []): Request
    {
        // No _account set — controller resolves from session.
        return Request::create('/api/auth/2fa/verify', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, User> $usersById */
    private function makeEntityTypeManager(array $usersById): EntityTypeManagerInterface
    {
        $storage = new class ($usersById) implements EntityStorageInterface {
            /** @param array<int, User> $usersById */
            public function __construct(private array $usersById) {}
            public function create(array $values = []): EntityInterface
            {
                throw new \BadMethodCallException();
            }
            public function load(int|string $id): ?EntityInterface
            {
                return $this->usersById[(int) $id] ?? null;
            }
            public function loadByKey(string $key, mixed $value): ?EntityInterface
            {
                return null;
            }
            public function loadMultiple(array $ids = []): array
            {
                return [];
            }
            public function save(EntityInterface $entity): int
            {
                \assert($entity instanceof User);
                $this->usersById[(int) $entity->id()] = $entity;
                return 2;
            }
            public function delete(array $entities): void {}
            public function getQuery(): EntityQueryInterface
            {
                throw new \BadMethodCallException();
            }
            public function getEntityTypeId(): string
            {
                return 'user';
            }
        };
        return new class ($storage) implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
            {
                return [];
            }
            public function __construct(private readonly EntityStorageInterface $storage) {}
            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                throw new \BadMethodCallException();
            }
            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}
            public function hasDefinition(string $entityTypeId): bool
            {
                return true;
            }
            public function getDefinitions(): array
            {
                return [];
            }
            public function getStorage(string $entityTypeId): EntityStorageInterface
            {
                return $this->storage;
            }
            public function getRepository(string $entityTypeId): EntityRepositoryInterface
            {
                // C-22 WP3: read/write path now goes through the canonical repository.
                return new StorageBackedStubRepository($this->storage);
            }
        };
    }
}
