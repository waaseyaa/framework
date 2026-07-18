<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\Bootstrap\ScheduleEntryRegistry;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

#[CoversClass(AbstractKernel::class)]
#[CoversClass(ScheduleEntryRegistry::class)]
final class AbstractKernelTest extends TestCase
{
    public function test_field_access_preflight_preserves_parameterless_boot_override_compatibility(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public bool $overrideCalled = false;

            public function runPreflightBoot(): void
            {
                $this->bootForFieldAccessPreflight();
            }

            protected function boot(): void
            {
                $this->overrideCalled = true;
            }
        };

        $kernel->runPreflightBoot();

        self::assertTrue($kernel->overrideCalled);
        self::assertSame(0, new \ReflectionMethod($kernel, 'boot')->getNumberOfParameters());
    }
    private string $projectRoot;

    protected function setUp(): void
    {
        putenv('WAASEYAA_APP_SECRET');
        $this->projectRoot = $this->createMinimalProjectRoot();
    }

    private function createMinimalProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_kernel_test_' . uniqid();
        mkdir($projectRoot . '/config', 0o755, true);
        mkdir($projectRoot . '/storage', 0o755, true);

        file_put_contents(
            $projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            "<?php\nreturn [\n    new \\Waaseyaa\\Entity\\EntityType(\n        id: 'test',\n        label: 'Test',\n        class: \\stdClass::class,\n        keys: ['id' => 'id'],\n    ),\n];",
        );

        return $projectRoot;
    }

    protected function tearDown(): void
    {
        putenv('WAASEYAA_APP_SECRET');
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function kernel_provides_project_root(): void
    {
        $kernel = new class ('/tmp/test-project') extends AbstractKernel {};

        $this->assertSame('/tmp/test-project', $kernel->getProjectRoot());
    }

    #[Test]
    public function kernel_boots_core_services(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };

        $kernel->publicBoot();

        $this->assertNotNull($kernel->getEntityTypeManager());
        $this->assertNotNull($kernel->getDatabase());
        $this->assertNotNull($kernel->getEventDispatcher());
    }

    #[Test]
    public function boot_is_idempotent(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public int $bootCount = 0;

            public function publicBoot(): void
            {
                $this->bootCount++;
                $this->boot();
            }
        };

        $kernel->publicBoot();
        $kernel->publicBoot();

        $this->assertSame(2, $kernel->bootCount);
        $this->assertInstanceOf(\Waaseyaa\Entity\EntityTypeManager::class, $kernel->getEntityTypeManager());
    }

    #[Test]
    public function boot_writes_manifest_cache_inside_fake_project_root(): void
    {
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'production'];",
        );
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('m', 32)));
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };

        $kernel->publicBoot();

        $cachePath = $this->projectRoot . '/storage/framework/packages.php';
        $this->assertFileExists($cachePath);
        $this->assertIsArray(require $cachePath);
    }

    // T011 — FR-010: kernel registers ScheduleEntriesInterface implementors at boot
    #[Test]
    public function registers_schedule_entries_at_boot(): void
    {
        $registerCallCount = 0;
        $entryClass        = $this->createSpyScheduleEntries(static function () use (&$registerCallCount): void {
            $registerCallCount++;
        });

        $kernel = new class ($this->projectRoot, null, $entryClass) extends AbstractKernel {
            public function __construct(
                string $projectRoot,
                mixed $logger,
                public readonly string $entryClass,
            ) {
                parent::__construct($projectRoot, $logger);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new \Waaseyaa\Foundation\Discovery\PackageManifest(
                    scheduleEntries: [$this->entryClass],
                );
            }
        };

        $kernel->publicBoot();

        $this->assertSame(1, $registerCallCount, 'register() must be called once per manifest schedule entry');
    }

    // T012 — FR-011: kernel boot fails closed when schedule entry has unresolvable dependency
    #[Test]
    public function fails_boot_on_unresolvable_schedule_entry(): void
    {
        $entryClass = $this->createEntryWithUnresolvableDep();

        $kernel = new class ($this->projectRoot, null, $entryClass) extends AbstractKernel {
            public function __construct(
                string $projectRoot,
                mixed $logger,
                public readonly string $entryClass,
            ) {
                parent::__construct($projectRoot, $logger);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new \Waaseyaa\Foundation\Discovery\PackageManifest(
                    scheduleEntries: [$this->entryClass],
                );
            }
        };

        $this->expectException(\Waaseyaa\Foundation\Kernel\Bootstrap\Exception\ScheduleEntryInstantiationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($entryClass, '/') . '/');

        $kernel->publicBoot();
    }

    // T013 — SC-004: kernel skips disabled_entries at boot
    #[Test]
    public function skips_disabled_schedule_entries(): void
    {
        $enabledCount  = 0;
        $disabledCount = 0;

        $enabledClass  = $this->createSpyScheduleEntries(static function () use (&$enabledCount): void {
            $enabledCount++;
        });
        $disabledClass = $this->createSpyScheduleEntries(static function () use (&$disabledCount): void {
            $disabledCount++;
        });

        $kernel = new class ($this->projectRoot, null, $enabledClass, $disabledClass) extends AbstractKernel {
            public function __construct(
                string $projectRoot,
                mixed $logger,
                public readonly string $enabledClass,
                public readonly string $disabledClass,
            ) {
                parent::__construct($projectRoot, $logger);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new \Waaseyaa\Foundation\Discovery\PackageManifest(
                    scheduleEntries: [$this->enabledClass, $this->disabledClass],
                );
            }

            protected function boot(): void
            {
                // Inject the disabled entry into config before booting.
                parent::boot();
            }

            /** @return array<string, mixed> */
            public function getConfig(): array
            {
                return array_merge(parent::getConfig(), [
                    'schedule' => ['disabled_entries' => [$this->disabledClass]],
                ]);
            }
        };

        // Override config before boot: patch config after compileManifest but before bootScheduleEntries.
        // Since config is read from the project root, inject the disabled_entries via
        // a subclass override of bootScheduleEntries().
        $kernelFinal = new class ($this->projectRoot, null, $enabledClass, $disabledClass) extends AbstractKernel {
            public function __construct(
                string $projectRoot,
                mixed $logger,
                public readonly string $enabledClass,
                public readonly string $disabledClass,
            ) {
                parent::__construct($projectRoot, $logger);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new \Waaseyaa\Foundation\Discovery\PackageManifest(
                    scheduleEntries: [$this->enabledClass, $this->disabledClass],
                );
            }

            protected function bootScheduleEntries(): void
            {
                // Inject disabled_entries into config for this step only.
                $saved = $this->config;
                $this->config = array_merge($this->config, [
                    'schedule' => ['disabled_entries' => [$this->disabledClass]],
                ]);
                parent::bootScheduleEntries();
                $this->config = $saved;
            }
        };

        $kernelFinal->publicBoot();

        $this->assertSame(1, $enabledCount, 'Enabled entry register() must be called');
        $this->assertSame(0, $disabledCount, 'Disabled entry register() must NOT be called');
    }

    /**
     * Creates a ScheduleEntriesInterface class whose register() calls the spy.
     *
     * @return class-string
     */
    private function createSpyScheduleEntries(\Closure $spy): string
    {
        $className = 'KernelTestSpyScheduleEntries_' . uniqid();
        $spyKey    = 'spy_' . $className;
        $GLOBALS[$spyKey] = $spy;

        eval(sprintf(
            'final class %s implements %s {
                public function register(%s $schedule): array {
                    ($GLOBALS["%s"])();
                    return [];
                }
            }',
            $className,
            ScheduleEntriesInterface::class,
            ScheduleInterface::class,
            $spyKey,
        ));

        /** @var class-string */
        return $className;
    }

    /**
     * Creates a ScheduleEntriesInterface class with a constructor dep that cannot be resolved.
     *
     * @return class-string
     */
    private function createEntryWithUnresolvableDep(): string
    {
        // Use a unique interface name as the unresolvable dep type.
        $depInterfaceName = 'UnresolvableDepInterface_' . uniqid();
        $className        = 'UnresolvableDepScheduleEntries_' . uniqid();

        eval(sprintf('interface %s {}', $depInterfaceName));
        eval(sprintf(
            'final class %s implements %s {
                public function __construct(private readonly %s $dep) {}
                public function register(%s $schedule): array { return []; }
            }',
            $className,
            ScheduleEntriesInterface::class,
            $depInterfaceName,
            ScheduleInterface::class,
        ));

        /** @var class-string */
        return $className;
    }
}
