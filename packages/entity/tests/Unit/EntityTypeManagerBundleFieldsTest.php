<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities\GroupBundleFixture;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\LoggerInterface;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/BundleFieldsFixtures.php';

#[CoversClass(EntityTypeManager::class)]
final class EntityTypeManagerBundleFieldsTest extends TestCase
{
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
    }

    #[Test]
    public function addBundleFieldsDelegatesToRegistryWhenEntityTypeIsMultiBundle(): void
    {
        $registry = new SpyRegistry();
        $manager = new EntityTypeManager($this->dispatcher, null, null, $registry);
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        $fakeField = new \stdClass();

        $manager->addBundleFields('group', 'business', ['email' => $fakeField]);

        self::assertSame(
            [['group', 'business', ['email' => $fakeField]]],
            $registry->bundleCalls,
        );
    }

    #[Test]
    public function addBundleFieldsThrowsWhenNoRegistryConfigured(): void
    {
        $manager = new EntityTypeManager($this->dispatcher);
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no FieldDefinitionRegistry configured');

        $manager->addBundleFields('group', 'business', []);
    }

    #[Test]
    public function addBundleFieldsThrowsForUnregisteredEntityType(): void
    {
        $registry = new SpyRegistry();
        $manager = new EntityTypeManager($this->dispatcher, null, null, $registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity type "group" is not registered.');

        $manager->addBundleFields('group', 'business', []);
    }

    #[Test]
    public function addBundleFieldsThrowsForEntityTypeWithoutBundleEntityType(): void
    {
        $registry = new SpyRegistry();
        $manager = new EntityTypeManager($this->dispatcher, null, null, $registry);
        $manager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
        ));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not declare bundleEntityType');

        $manager->addBundleFields('user', 'business', []);
    }

    /**
     * WP03 #1257 (K1): Bundle identifiers containing the reserved "__" separator
     * are rejected at registration time so that no downstream storage or query
     * code paths can be reached with a malformed bundle id. The structural guard
     * complements the formatting guard in
     * {@see \Waaseyaa\EntityStorage\SqlSchemaHandler::resolveSubtableName()}.
     */
    #[Test]
    public function addBundleFieldsThrowsForBundleIdContainingReservedSeparator(): void
    {
        $registry = new SpyRegistry();
        $manager = new EntityTypeManager($this->dispatcher, null, null, $registry);
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        try {
            $manager->addBundleFields('group', 'business__nested', []);
            self::fail('Expected InvalidArgumentException not thrown');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('reserved separator "__"', $e->getMessage());
            self::assertStringContainsString('"business__nested"', $e->getMessage());
            self::assertStringContainsString('"group"', $e->getMessage());
        }

        // Guard must fire before delegating to the registry.
        self::assertSame([], $registry->bundleCalls);
    }

    #[Test]
    public function registerEntityTypePushesCoreFieldsIntoRegistry(): void
    {
        $registry = new SpyRegistry();
        $manager = new EntityTypeManager($this->dispatcher, null, null, $registry);

        // Pattern 1: an attribute-first fixture with `#[Field] public string $name`
        // produces a core `name` field of type `string`. EntityType::fromClass()
        // is the canonical attribute-first entry point; bundle-scoped fields are
        // injected separately via `addBundleFields()` (deferred to a follow-on
        // mission for attribute support).
        $manager->registerEntityType(EntityType::fromClass(
            class: GroupBundleFixture::class,
            bundleEntityType: 'group_type',
        ));

        self::assertCount(1, $registry->coreCalls);
        self::assertSame('group', $registry->coreCalls[0][0]);
        self::assertArrayHasKey('name', $registry->coreCalls[0][1]);
        self::assertInstanceOf(FieldDefinitionInterface::class, $registry->coreCalls[0][1]['name']);
        self::assertSame('string', $registry->coreCalls[0][1]['name']->getType());
    }

    #[Test]
    public function addBundleFieldsEmitsMissingSubtableNoticeWhenProbeReportsAbsent(): void
    {
        $registry = new SpyRegistry();
        $logger = new SpyLogger();
        $manager = new EntityTypeManager(
            $this->dispatcher,
            null,
            null,
            $registry,
            $logger,
            // Probe reports the subtable is absent.
            static fn(string $_id, string $_bundle): bool => false,
        );
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        $manager->addBundleFields('group', 'business', ['email' => new \stdClass()]);

        self::assertCount(1, $logger->notices);
        self::assertStringContainsString('[BUNDLE_SUBTABLE_MISSING]', $logger->notices[0]);
        self::assertStringContainsString('"group"', $logger->notices[0]);
        self::assertStringContainsString('"business"', $logger->notices[0]);
        self::assertStringContainsString('group__business', $logger->notices[0]);
    }

    #[Test]
    public function addBundleFieldsSuppressesNoticeWhenProbeReportsSubtablePresent(): void
    {
        $registry = new SpyRegistry();
        $logger = new SpyLogger();
        $manager = new EntityTypeManager(
            $this->dispatcher,
            null,
            null,
            $registry,
            $logger,
            static fn(string $_id, string $_bundle): bool => true,
        );
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        $manager->addBundleFields('group', 'business', ['email' => new \stdClass()]);

        self::assertSame([], $logger->notices);
    }

    #[Test]
    public function missingSubtableNoticeFiresOncePerEntityTypeBundlePair(): void
    {
        $registry = new SpyRegistry();
        $logger = new SpyLogger();
        $manager = new EntityTypeManager(
            $this->dispatcher,
            null,
            null,
            $registry,
            $logger,
            static fn(string $_id, string $_bundle): bool => false,
        );
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        // Two registrations for the same (entity_type, bundle) — second is silent.
        $manager->addBundleFields('group', 'business', ['email' => new \stdClass()]);
        $manager->addBundleFields('group', 'business', ['phone' => new \stdClass()]);
        // A different bundle on the same entity type — fires its own notice.
        $manager->addBundleFields('group', 'organization', ['vat' => new \stdClass()]);

        self::assertCount(2, $logger->notices);
        self::assertStringContainsString('"business"', $logger->notices[0]);
        self::assertStringContainsString('"organization"', $logger->notices[1]);
    }

    #[Test]
    public function missingSubtableNoticeIsSilentWhenNoProbeConfigured(): void
    {
        $registry = new SpyRegistry();
        $logger = new SpyLogger();
        $manager = new EntityTypeManager(
            $this->dispatcher,
            null,
            null,
            $registry,
            $logger,
            // No probe — defaults to null; behavior matches pre-#1376 callers.
        );
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        $manager->addBundleFields('group', 'business', ['email' => new \stdClass()]);

        self::assertSame([], $logger->notices);
    }

    #[Test]
    public function missingSubtableProbeFailureIsSwallowedAndLoggedAtInfo(): void
    {
        $registry = new SpyRegistry();
        $logger = new SpyLogger();
        $manager = new EntityTypeManager(
            $this->dispatcher,
            null,
            null,
            $registry,
            $logger,
            static function (string $_id, string $_bundle): bool {
                throw new \RuntimeException('schema unreachable');
            },
        );
        $manager->registerEntityType(new EntityType(
            id: 'group',
            label: 'Group',
            class: TestEntity::class,
            bundleEntityType: 'group_type',
        ));

        // Probe failure must not fail registration.
        $manager->addBundleFields('group', 'business', ['email' => new \stdClass()]);

        self::assertSame([], $logger->notices, 'Notice must not fire when probe throws.');
        self::assertCount(1, $logger->infos, 'Probe failure must be logged at info.');
        self::assertStringContainsString('schema unreachable', $logger->infos[0]);
    }

    #[Test]
    public function getFieldRegistryReturnsRegistryOrThrows(): void
    {
        $registry = new SpyRegistry();
        $withRegistry = new EntityTypeManager($this->dispatcher, null, null, $registry);
        self::assertSame($registry, $withRegistry->getFieldRegistry());

        $withoutRegistry = new EntityTypeManager($this->dispatcher);
        $this->expectException(\RuntimeException::class);
        $withoutRegistry->getFieldRegistry();
    }
}

/**
 * In-memory spy registry used only by this test.
 */
final class SpyRegistry implements FieldDefinitionRegistryInterface
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $coreCalls = [];

    /** @var list<array{0: string, 1: string, 2: array<string|int, object>}> */
    public array $bundleCalls = [];

    public function registerCoreFields(string $entityTypeId, array $fields): void
    {
        $this->coreCalls[] = [$entityTypeId, $fields];
    }

    public function mergeCoreFields(string $entityTypeId, array $fields): void
    {
        $this->coreCalls[] = [$entityTypeId, $fields];
    }

    public function registerBundleFields(string $entityTypeId, string $bundle, array $fields): void
    {
        $this->bundleCalls[] = [$entityTypeId, $bundle, $fields];
    }

    public function coreFieldsFor(string $entityTypeId): array
    {
        foreach ($this->coreCalls as [$id, $fields]) {
            if ($id === $entityTypeId) {
                return $fields;
            }
        }
        return [];
    }

    public function bundleFieldsFor(string $entityTypeId, string $bundle): array
    {
        foreach ($this->bundleCalls as [$id, $b, $fields]) {
            if ($id === $entityTypeId && $b === $bundle) {
                /** @var array<string, object> $fields */
                return $fields;
            }
        }
        return [];
    }

    public function bundleNamesFor(string $entityTypeId): array
    {
        $names = [];
        foreach ($this->bundleCalls as [$id, $b, $_fields]) {
            if ($id === $entityTypeId) {
                $names[$b] = true;
            }
        }
        return \array_keys($names);
    }

    public function bundlesDefiningField(string $entityTypeId, string $fieldName): array
    {
        $bundles = [];
        foreach ($this->bundleCalls as [$id, $b, $fields]) {
            if ($id !== $entityTypeId) {
                continue;
            }
            foreach ($fields as $key => $field) {
                $name = \is_string($key) ? $key : (\is_object($field) && \method_exists($field, 'getName') ? $field->getName() : null);
                if ($name === $fieldName) {
                    $bundles[$b] = true;
                    break;
                }
            }
        }
        return \array_keys($bundles);
    }
}

/**
 * In-memory spy logger that records `notice` and `info` calls. The other
 * severity methods are no-ops because the bundle-subtable-missing path only
 * uses these two levels.
 */
final class SpyLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $notices = [];

    /** @var list<string> */
    public array $infos = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}
    public function alert(string|\Stringable $message, array $context = []): void {}
    public function critical(string|\Stringable $message, array $context = []): void {}
    public function error(string|\Stringable $message, array $context = []): void {}
    public function warning(string|\Stringable $message, array $context = []): void {}

    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->notices[] = (string) $message;
    }

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->infos[] = (string) $message;
    }

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
    {
        match ($level) {
            LogLevel::Notice => $this->notices[] = (string) $message,
            LogLevel::Info => $this->infos[] = (string) $message,
            default => null,
        };
    }
}
