<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Access\PermissionHandlerInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures\CataloguedRolesFixtureProvider;
use Waaseyaa\Foundation\Tests\Unit\Kernel\Fixtures\UncataloguedRolesFixtureProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;
use Waaseyaa\User\RoleRepository;

/**
 * Coverage-bearing companion to the real-kernel boot proof in
 * tests/Integration/PermissionCatalogue (#2788 G1): the kernel composes ONE
 * permission catalogue after providers boot, refuses an uncatalogued
 * provider-declared role grant, and serves the composed instance through
 * its public accessor and the handler container.
 *
 * Boots the production kernel path through the anonymous-subclass +
 * publicBoot() pattern codified by KernelBundleSubtableMaterializationTest.
 */
#[CoversClass(AbstractKernel::class)]
final class PermissionCatalogueCompositionTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_permission_catalogue_unit_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents($this->projectRoot . '/config/entity-types.php', <<<'PHP'
            <?php
            return [
                new \Waaseyaa\Entity\EntityType(
                    id: 'catalogue_widget',
                    label: 'Widget',
                    class: \stdClass::class,
                    keys: ['id' => 'id', 'label' => 'name'],
                ),
            ];
            PHP);
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function boot_refuses_a_role_granting_a_permission_no_catalogue_declares(): void
    {
        $this->writeRootComposer([UncataloguedRolesFixtureProvider::class]);
        $kernel = $this->newKernel();

        try {
            $kernel->publicBoot();
            self::fail('Expected boot to be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Permission catalogue validation failed', $exception->getMessage());
            self::assertStringContainsString('Role "phantom" grants permission "walk through walls"', $exception->getMessage());
            self::assertStringContainsString(UncataloguedRolesFixtureProvider::class, $exception->getMessage());
            self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function boot_composes_one_catalogue_from_manifest_permissions_and_provider_contributions(): void
    {
        $this->writeRootComposer(
            [CataloguedRolesFixtureProvider::class],
            ['operate widgets' => ['title' => 'Operate widgets']],
        );
        $kernel = $this->newKernel();
        $kernel->publicBoot();

        $catalogue = $kernel->permissionCatalogue();
        self::assertTrue($catalogue->hasPermission(CataloguedRolesFixtureProvider::PERMISSION), 'provider contribution');
        self::assertTrue($catalogue->hasPermission('operate widgets'), 'root extra.waaseyaa.permissions');
        self::assertSame('Curate the fixture library.', $catalogue->getPermissions()[CataloguedRolesFixtureProvider::PERMISSION]['description']);
        self::assertSame('', $catalogue->getPermissions()['operate widgets']['description']);

        $container = $kernel->buildHandlerContainer();
        self::assertSame($catalogue, $container->get(PermissionHandlerInterface::class), 'the container serves the kernel-owned instance');
        self::assertSame($catalogue, $container->get(PermissionHandlerInterface::class), 'one instance per process');

        $roles = $container->get(RoleRepository::class);
        self::assertInstanceOf(RoleRepository::class, $roles);
        self::assertSame([CataloguedRolesFixtureProvider::PERMISSION], $roles->get('curator')?->permissions);
    }

    #[Test]
    public function the_catalogue_is_unavailable_before_boot(): void
    {
        $this->writeRootComposer([CataloguedRolesFixtureProvider::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('composed during boot');

        $this->newKernel()->permissionCatalogue();
    }

    /** @param list<class-string> $providers @param array<string, array{title: string}> $permissions */
    private function writeRootComposer(array $providers, array $permissions = []): void
    {
        $extra = ['providers' => $providers];
        if ($permissions !== []) {
            $extra['permissions'] = $permissions;
        }
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/permission-catalogue-unit',
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
