<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase25;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Groups\Group;
use Waaseyaa\Groups\GroupsServiceProvider;

#[CoversClass(ProviderRegistry::class)]
#[CoversClass(EntityTypeRegistrationCollisionException::class)]
final class EntityTypeCollisionIntegrationTest extends TestCase
{
    private string $projectRoot;

    protected function tearDown(): void
    {
        $registryProperty = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
        $registryProperty->setValue(null, null);

        if (!isset($this->projectRoot) || !is_dir($this->projectRoot)) {
            return;
        }

        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function canonical_groups_provider_boots_cleanly(): void
    {
        $kernel = $this->bootKernelWithProviders([
            GroupsServiceProvider::class,
        ]);

        self::assertSame(Group::class, $kernel->getEntityTypeManager()->getDefinition('group')->getClass());
        self::assertTrue($kernel->getEntityTypeManager()->hasDefinition('group_type'));
    }

    #[Test]
    public function shadow_collision_fails_boot_with_provenance_aware_message(): void
    {
        $this->writeProjectFiles([
            GroupsServiceProvider::class,
            Phase25ConflictingGroupProvider::class,
        ]);

        $this->expectException(EntityTypeRegistrationCollisionException::class);
        $this->expectExceptionMessage('[ENTITY_TYPE_SHADOW_COLLISION]');
        $this->expectExceptionMessage('group');
        $this->expectExceptionMessage(GroupsServiceProvider::class);
        $this->expectExceptionMessage(Phase25ConflictingGroupProvider::class);
        $this->expectExceptionMessage(Group::class);
        $this->expectExceptionMessage(Phase25ShadowGroup::class);

        $this->newKernel()->publicBoot();
    }

    #[Test]
    public function consumer_owned_distinct_entity_type_still_boots(): void
    {
        $kernel = $this->bootKernelWithProviders([
            GroupsServiceProvider::class,
            Phase25DistinctEntityTypeProvider::class,
        ]);

        self::assertSame(
            Phase25ConsumerGroupExtension::class,
            $kernel->getEntityTypeManager()->getDefinition('consumer_group_extension')->getClass(),
        );
    }

    /**
     * @param list<class-string> $providers
     */
    private function bootKernelWithProviders(array $providers): AbstractKernel
    {
        $this->writeProjectFiles($providers);

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        return $kernel;
    }

    /**
     * @param list<class-string> $providers
     */
    private function writeProjectFiles(array $providers): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_entity_type_collision_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );

        file_put_contents(
            $this->projectRoot . '/composer.json',
            json_encode([
                'name' => 'waaseyaa/phase25-fixture',
                'type' => 'project',
                'extra' => [
                    'waaseyaa' => [
                        'providers' => $providers,
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    private function newKernel(): AbstractKernel
    {
        return new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
    }
}

final class Phase25ConflictingGroupProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'group',
            label: 'Shadow group',
            class: Phase25ShadowGroup::class,
            keys: ['id' => 'gid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }
}

final class Phase25DistinctEntityTypeProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'consumer_group_extension',
            label: 'Consumer group extension',
            class: Phase25ConsumerGroupExtension::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        ));
    }
}

#[ContentEntityType(id: 'group')]
#[ContentEntityKeys(id: 'gid', uuid: 'uuid', label: 'name')]
final class Phase25ShadowGroup extends ContentEntityBase {}

#[ContentEntityType(id: 'consumer_group_extension')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label')]
final class Phase25ConsumerGroupExtension extends ContentEntityBase {}
