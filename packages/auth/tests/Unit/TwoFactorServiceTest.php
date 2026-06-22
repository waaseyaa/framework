<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Auth\TwoFactorSetupResult;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\User\User;

#[CoversClass(TwoFactorService::class)]
#[CoversClass(TwoFactorSetupResult::class)]
final class TwoFactorServiceTest extends TestCase
{
    private TwoFactorManager $manager;
    private TwoFactorService $service;

    /** @var list<array{user: User, op: string}> */
    private array $saveLog = [];

    protected function setUp(): void
    {
        $this->manager = new TwoFactorManager();
        $this->saveLog = [];

        $storage = new class ($this->saveLog) implements EntityStorageInterface {
            /** @param list<array{user: User, op: string}> $saveLog */
            public function __construct(private array &$saveLog)
            {
            }

            public function create(array $values = []): EntityInterface
            {
                throw new \BadMethodCallException('not used in this test');
            }

            public function load(int|string $id): ?EntityInterface
            {
                return null;
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
                $this->saveLog[] = ['user' => $entity, 'op' => 'save'];

                return 2; // SAVED_UPDATED
            }

            public function delete(array $entities): void
            {
            }

            public function getQuery(): EntityQueryInterface
            {
                throw new \BadMethodCallException('not used in this test');
            }

            public function getEntityTypeId(): string
            {
                return 'user';
            }
        };

        $typeManager = new class ($storage) implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
            public function __construct(private readonly EntityStorageInterface $storage)
            {
            }

            public function getDefinition(string $entityTypeId): EntityTypeInterface
            {
                throw new \BadMethodCallException('not used in this test');
            }

            public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
            }

            public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
            {
            }

            public function hasDefinition(string $entityTypeId): bool
            {
                return $entityTypeId === 'user';
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
                throw new \BadMethodCallException('not used in this test');
            }
        };

        $this->service = new TwoFactorService($this->manager, $typeManager);
    }

    public function testSetupReturnsResultWithAllFields(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);

        $result = $this->service->setup($user);

        $this->assertNotEmpty($result->secret);
        $this->assertStringStartsWith('otpauth://totp/', $result->qrCodeUri);
        $this->assertCount(8, $result->recoveryCodes);
        foreach ($result->recoveryCodes as $code) {
            $this->assertIsString($code);
            $this->assertNotEmpty($code);
        }
    }

    public function testSetupDoesNotPersist(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);

        $this->service->setup($user);

        $this->assertSame([], $this->saveLog, 'setup must not persist anything');
        $this->assertNull($user->getTwoFactorSecret());
    }

    public function testSetupThrowsWhenAlreadyEnabled(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $user->setTwoFactorSecret('ALREADYENABLED');

        $this->expectException(\RuntimeException::class);
        $this->service->setup($user);
    }

    public function testEnableRejectsWrongFirstCode(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);

        $ok = $this->service->enable($user, $setup->secret, $setup->recoveryCodes, '000000');

        $this->assertFalse($ok);
        $this->assertSame([], $this->saveLog, 'enable must not persist on failed verification');
        $this->assertNull($user->getTwoFactorSecret());
    }

    public function testEnablePersistsOnSuccess(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);

        $ok = $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $this->assertTrue($ok);
        $this->assertCount(1, $this->saveLog);
        $this->assertSame($setup->secret, $user->getTwoFactorSecret());
        $hashes = $user->getTwoFactorRecoveryCodesHash();
        $this->assertNotNull($hashes);
        $this->assertCount(8, $hashes);
        foreach ($hashes as $hash) {
            $this->assertStringStartsWith('$argon2id$', $hash);
        }
    }

    public function testVerifyAcceptsCurrentTotp(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $current = $this->manager->getCurrentCode($setup->secret);

        $this->assertTrue($this->service->verify($user, $current));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $this->assertFalse($this->service->verify($user, '000000'));
    }

    public function testVerifyConsumesRecoveryCode(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);
        $recoveryCode = $setup->recoveryCodes[0];

        // First use: succeeds.
        $this->assertTrue($this->service->verify($user, $recoveryCode));
        $remaining = $user->getTwoFactorRecoveryCodesHash();
        $this->assertNotNull($remaining);
        $this->assertCount(7, $remaining, 'recovery code must be consumed');

        // Second use: fails (code already consumed).
        $this->assertFalse($this->service->verify($user, $recoveryCode));
    }

    public function testVerifyReturnsFalseWhenNotEnabled(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);

        $this->assertFalse($this->service->verify($user, '123456'));
    }

    public function testDisableWipesBothFields(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $this->service->disable($user);

        $this->assertNull($user->getTwoFactorSecret());
        $this->assertNull($user->getTwoFactorRecoveryCodesHash());
    }

    public function testIsEnabledReflectsState(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);

        $this->assertFalse($this->service->isEnabled($user));

        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $this->assertTrue($this->service->isEnabled($user));

        $this->service->disable($user);

        $this->assertFalse($this->service->isEnabled($user));
    }

    public function testVerifyRejectsReplayedTotpWithinWindow(): void
    {
        $user = User::make(['uid' => 1, 'name' => 'alice', 'mail' => 'alice@example.com']);
        $setup = $this->service->setup($user);
        $firstCode = $this->manager->getCurrentCode($setup->secret);
        $this->service->enable($user, $setup->secret, $setup->recoveryCodes, $firstCode);

        $current = $this->manager->getCurrentCode($setup->secret);

        // First use of the code succeeds and consumes its time step.
        $this->assertTrue($this->service->verify($user, $current));

        // Immediate reuse of the same code (still within its validity window)
        // must be rejected as a replay.
        $this->assertFalse(
            $this->service->verify($user, $current),
            'a TOTP code must not be accepted twice within its validity window',
        );
    }
}
