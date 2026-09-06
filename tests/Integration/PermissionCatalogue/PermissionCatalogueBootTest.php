<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PermissionCatalogue;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Access\PermissionHandlerInterface;
use Waaseyaa\CLI\Handler\PermissionListHandler;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Tests\Integration\PermissionCatalogue\Fixtures\CataloguedRolesProvider;
use Waaseyaa\Tests\Integration\PermissionCatalogue\Fixtures\UncataloguedRolesProvider;
use Waaseyaa\User\RoleRepository;

/**
 * The kernel composes ONE permission catalogue at boot — the compiled
 * manifest's `extra.waaseyaa.permissions` entries plus every provider
 * implementing `ProvidesPermissionsInterface` — binds it as
 * `PermissionHandlerInterface`, and refuses to boot when a provider-declared
 * role grants a permission that catalogue does not know (#2788, G1 closed).
 *
 * Uses the anonymous-subclass + real-projectRoot pattern codified in
 * KernelBundleSubtableMaterializationTest; see
 * tests/Architecture/NoKernelSubclassesInTestsTest for enforcement.
 */
#[CoversNothing]
final class PermissionCatalogueBootTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_permission_catalogue_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage/framework', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents($this->projectRoot . '/config/entity-types.php', <<<'PHP'
            <?php
            return [
                new \Waaseyaa\Entity\EntityType(
                    id: 'note',
                    label: 'Note',
                    class: \Waaseyaa\Note\Note::class,
                    keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
                ),
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry')->setValue(null, null);
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function a_role_granting_an_uncatalogued_permission_fails_boot(): void
    {
        $this->writeRootComposer([UncataloguedRolesProvider::class]);

        try {
            $this->newKernel()->publicBoot();
            self::fail('Expected boot to be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Role "ghost" grants permission "haunt the site"', $exception->getMessage());
            self::assertStringContainsString(UncataloguedRolesProvider::class, $exception->getMessage());
        }
    }

    #[Test]
    public function the_booted_kernel_binds_one_catalogue_composed_from_the_manifest_and_provider_contributions(): void
    {
        $this->writeRootComposer(
            [CataloguedRolesProvider::class],
            ['operate the root' => ['title' => 'Operate the root application']],
        );

        $kernel = $this->newKernel();
        $kernel->publicBoot();

        $container = $kernel->buildHandlerContainer();
        $catalogue = $container->get(PermissionHandlerInterface::class);
        self::assertInstanceOf(PermissionHandlerInterface::class, $catalogue);
        self::assertTrue($catalogue->hasPermission(CataloguedRolesProvider::PERMISSION), 'provider contribution');
        self::assertTrue($catalogue->hasPermission('operate the root'), 'root composer.json extra.waaseyaa.permissions');
        self::assertSame('', $catalogue->getPermissions()['operate the root']['description']);
        self::assertSame($catalogue, $kernel->permissionCatalogue(), 'the container serves the kernel-owned instance');
        self::assertSame($catalogue, $container->get(PermissionHandlerInterface::class), 'one instance per process');

        $roles = $container->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);
        self::assertSame([CataloguedRolesProvider::PERMISSION], $roles->get('reviewer')?->permissions);

        // The canonical CLI consumer resolves through the same binding.
        self::assertInstanceOf(PermissionListHandler::class, $container->get(PermissionListHandler::class));
    }

    /** @param list<class-string> $providers @param array<string, array{title: string}> $permissions */
    private function writeRootComposer(array $providers, array $permissions = []): void
    {
        $extra = ['providers' => $providers];
        if ($permissions !== []) {
            $extra['permissions'] = $permissions;
        }
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/permission-catalogue-boot',
            'extra' => ['waaseyaa' => $extra],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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
