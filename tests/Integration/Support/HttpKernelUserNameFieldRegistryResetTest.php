<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadLayout;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\User\User;

/**
 * Regression for the alpha.297 ci/random-order failure "Conflicting field-read
 * definitions for user.name": HttpKernelTest boots a kernel with
 * UserServiceProvider, which installs User::$name as Protected process-wide.
 * A later test that hydrates fixture TestEntity (name Public) as type id
 * `user` without its own registry then merges the leaked map and throws.
 *
 * The merge itself is correct and must stay fail-closed. The kernel test
 * must reset the process-wide registry so later tests see a clean fallback.
 */
#[CoversNothing]
final class HttpKernelUserNameFieldRegistryResetTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessFieldReadRuntime::reset();
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
    }

    #[Test]
    public function leakedUserRegistryConflictsWithFixtureUserName(): void
    {
        $this->installLeakedUserRegistry();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Conflicting field-read definitions for user.name.');

        $this->hydrateFixtureUserWithoutLocalRegistry();
    }

    #[Test]
    public function resetClearsLeakedUserRegistrySoFixtureUserNameHydrates(): void
    {
        $this->installLeakedUserRegistry();
        ProcessFieldReadRuntime::reset();

        $layout = $this->hydrateFixtureUserWithoutLocalRegistry();

        self::assertSame(FieldReadLevel::Public, $layout->levels()['name'] ?? null);

        $entityRuntimeRegistry = new \ReflectionProperty(EntityReadRuntime::class, 'fieldRegistry')->getValue();
        $contentEntityRegistry = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->getValue();
        self::assertNull($entityRuntimeRegistry);
        self::assertNull($contentEntityRegistry);
    }

    #[Test]
    public function identicalUserDefinitionsMayOverlayWithoutThrowing(): void
    {
        $userType = EntityType::fromClass(User::class);
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('user', $userType->getFieldDefinitions());
        ContentEntityBase::setFieldRegistry($registry);

        $layout = EntityReadRuntime::layoutFor(
            User::class,
            ['uid' => 1, 'uuid' => 'u', 'name' => 'ada'],
            'user',
            $userType->getKeys(),
            null,
            true,
            $userType->getFieldDefinitions(),
        );

        self::assertSame(FieldReadLevel::Protected, $layout->levels()['name'] ?? null);
    }

    private function installLeakedUserRegistry(): void
    {
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('user', EntityType::fromClass(User::class)->getFieldDefinitions());
        ContentEntityBase::setFieldRegistry($registry);
    }

    private function hydrateFixtureUserWithoutLocalRegistry(): EntityReadLayout
    {
        $entityType = new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        );

        return EntityReadRuntime::layoutFor(
            TestEntity::class,
            ['title' => 'Someone', 'status' => 1],
            'user',
            $entityType->getKeys(),
            null,
            true,
            $entityType->getFieldDefinitions(),
        );
    }
}
