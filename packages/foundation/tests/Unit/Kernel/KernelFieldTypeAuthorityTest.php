<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

/**
 * End-to-end kernel hand-off (#2786 B1): the compiled manifest's `field_types`
 * inventory becomes the one boot-scoped `FieldTypeManager`, that exact instance
 * admits every field the kernel's registry accepts, and the kernel-services bus
 * serves it to providers.
 */
#[CoversClass(AbstractKernel::class)]
#[CoversClass(ProviderRegistryKernelServices::class)]
final class KernelFieldTypeAuthorityTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        putenv('WAASEYAA_APP_SECRET');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_kernel_field_types_' . uniqid();
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        // An application entity type declaring a field of the manifest plugin's
        // type: registration runs the registry's fail-closed admission, which
        // only the boot-scoped (manifest-fed) registry can pass.
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'kernel_ledger',\n        label: 'Ledger',\n"
            . "        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n"
            . "        _fieldDefinitions: ['price' => ['type' => 'kernel_money', 'label' => 'Price']],\n    ),\n];",
        );
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        putenv('WAASEYAA_APP_SECRET');
        new Filesystem()->remove($this->projectRoot);
    }

    #[Test]
    public function boot_admits_manifest_field_types_into_the_registry_and_the_bus(): void
    {
        $pluginClass = ExtensionFieldTypeFixture::declare('kernel_money');

        $kernel = new class ($this->projectRoot, $pluginClass) extends AbstractKernel {
            public function __construct(string $projectRoot, private readonly string $pluginClass)
            {
                parent::__construct($projectRoot, new NullLogger());
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            public function busFieldTypes(): ?object
            {
                $providers = $this->providers;

                return new ProviderRegistryKernelServices(
                    $this->entityTypeManager,
                    $this->database,
                    $this->dispatcher,
                    $this->logger,
                    static fn(): array => $providers,
                    manifest: $this->manifest,
                )->get(FieldTypeManagerInterface::class);
            }

            protected function compileManifest(): void
            {
                $this->manifest = new PackageManifest(
                    providers: [],
                    fieldTypes: ['kernel_money' => $this->pluginClass],
                );
            }
        };

        $kernel->publicBoot();

        $registry = $kernel->getEntityTypeManager()->getFieldRegistry();
        self::assertInstanceOf(FieldDefinitionRegistry::class, $registry);
        self::assertSame('kernel_money', $registry->coreFieldsFor('kernel_ledger')['price']->getType());
        $bootScoped = $registry->fieldTypeManager();
        self::assertInstanceOf(FieldTypeManager::class, $bootScoped);
        self::assertTrue($bootScoped->hasDefinition('kernel_money'));
        self::assertSame($pluginClass, $bootScoped->getDefinition('kernel_money')->class);
        self::assertTrue($bootScoped->hasDefinition('string'));

        self::assertSame($bootScoped, $kernel->getFieldTypeManager());
        self::assertSame($bootScoped, $kernel->busFieldTypes());
        self::assertFalse(FieldTypeManager::default()->hasDefinition('kernel_money'));
    }
}
