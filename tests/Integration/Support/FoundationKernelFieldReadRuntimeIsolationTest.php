<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Support;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\User\User;

/**
 * #2514 executable proofs: remaining kernel-boot tests leaked process-wide
 * field-read state, and the #2513 helper's omission of
 * EntityReadRuntime::$immutableSemanticTemplates is not part of the
 * user.name merge-conflict contamination.
 */
#[CoversNothing]
final class FoundationKernelFieldReadRuntimeIsolationTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        ProcessFieldReadRuntime::reset();
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_2514_isolation_' . uniqid();
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            <<<'PHP'
                <?php
                return [
                    new \Waaseyaa\Entity\EntityType(
                        id: 'kernel_test_widget',
                        label: 'Widget',
                        class: \stdClass::class,
                        keys: ['id' => 'wid', 'uuid' => 'uuid', 'bundle' => 'type', 'label' => 'name'],
                        bundleEntityType: 'kernel_test_widget_type',
                    ),
                    new \Waaseyaa\Entity\EntityType(
                        id: 'kernel_test_widget_type',
                        label: 'Widget type',
                        class: \stdClass::class,
                        keys: ['id' => 'id', 'label' => 'label'],
                    ),
                ];
                PHP
        );
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function contentEntityBaseReflectionResetLeavesEntityReadRuntimeRegistry(): void
    {
        $this->bootKernelAndRegisterGizmoField();

        $property = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $property->setValue(null, null);

        $contentRegistry = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->getValue();
        $runtimeRegistry = new \ReflectionProperty(EntityReadRuntime::class, 'fieldRegistry')->getValue();

        self::assertNull($contentRegistry);
        self::assertInstanceOf(FieldDefinitionRegistry::class, $runtimeRegistry);
        self::assertArrayHasKey('gizmo_code', $runtimeRegistry->bundleFieldsFor('kernel_test_widget', 'gizmo'));
        self::assertInstanceOf(\Waaseyaa\Access\FieldReadGuard::class, EntityReadRuntime::guard());
    }

    #[Test]
    public function processFieldReadRuntimeResetClearsKernelBootedRegistryAndGuard(): void
    {
        $this->bootKernelAndRegisterGizmoField();
        ProcessFieldReadRuntime::reset();

        $contentRegistry = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->getValue();
        $runtimeRegistry = new \ReflectionProperty(EntityReadRuntime::class, 'fieldRegistry')->getValue();
        $manager = new \ReflectionProperty(ContentEntityBase::class, 'entityTypeManager')->getValue();

        self::assertNull($contentRegistry);
        self::assertNull($runtimeRegistry);
        self::assertNull($manager);
        self::assertNull(EntityReadRuntime::guard());
    }

    #[Test]
    public function leftoverImmutableSemanticTemplatesDoNotCauseUserNameConflictAfterReset(): void
    {
        $userType = EntityType::fromClass(User::class);
        $registry = new FieldDefinitionRegistry();
        $registry->registerCoreFields('user', $userType->getFieldDefinitions());
        ContentEntityBase::setFieldRegistry($registry);
        EntityReadRuntime::layoutFor(
            User::class,
            ['uid' => 1, 'uuid' => 'u', 'name' => 'ada'],
            'user',
            $userType->getKeys(),
            null,
            true,
            $userType->getFieldDefinitions(),
        );

        $templatesBeforeReset = $this->immutableSemanticTemplates();
        self::assertNotSame([], $templatesBeforeReset);

        ProcessFieldReadRuntime::reset();

        $templatesAfterReset = $this->immutableSemanticTemplates();
        self::assertSame($templatesBeforeReset, $templatesAfterReset);
        self::assertNull(new \ReflectionProperty(EntityReadRuntime::class, 'fieldRegistry')->getValue());

        $fixtureType = new EntityType(
            id: 'user',
            label: 'User',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        );
        $layout = EntityReadRuntime::layoutFor(
            TestEntity::class,
            ['title' => 'Someone', 'status' => 1],
            'user',
            $fixtureType->getKeys(),
            null,
            true,
            $fixtureType->getFieldDefinitions(),
        );

        self::assertSame(FieldReadLevel::Public, $layout->levels()['name'] ?? null);
    }

    private function bootKernelAndRegisterGizmoField(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();
        $kernel->getEntityTypeManager()->addBundleFields('kernel_test_widget', 'gizmo', [
            'gizmo_code' => new FieldDefinition(
                name: 'gizmo_code',
                type: 'string',
                targetEntityTypeId: 'kernel_test_widget',
                targetBundle: 'gizmo',
            ),
        ]);
    }

    /** @return array<string, object> */
    private function immutableSemanticTemplates(): array
    {
        /** @var array<string, object> $templates */
        $templates = new \ReflectionProperty(EntityReadRuntime::class, 'immutableSemanticTemplates')->getValue();

        return $templates;
    }
}
