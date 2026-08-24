<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\Bootstrap\ScheduleEntryRegistry;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

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
        ProcessFieldReadRuntime::reset();
        putenv('WAASEYAA_APP_SECRET');
        (new Filesystem())->remove($this->projectRoot);
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
    public function production_runtime_schema_assertion_refuses_missing_sql_tables(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();

        try {
            new \ReflectionMethod(AbstractKernel::class, 'assertProductionEntityStorageSchema')->invoke($kernel);
            self::fail('Missing SQL-backed tables must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB106', $exception->getMessage());
        }
    }

    #[Test]
    public function production_runtime_schema_assertion_accepts_materialized_sql_tables(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };
        $kernel->publicBoot();
        new EntitySchemaSync($kernel->getDatabase())->syncAll($kernel->getEntityTypeManager()->getDefinitions());

        new \ReflectionMethod(AbstractKernel::class, 'assertProductionEntityStorageSchema')->invoke($kernel);

        self::assertTrue($kernel->getDatabase()->schema()->tableExists('test'));
    }

    #[Test]
    public function kernel_freezes_secret_policy_after_provider_registration(): void
    {
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            public function publicSecretRegistry(): SecretResolverRegistry
            {
                return $this->secretResolverRegistry();
            }
        };

        $kernel->publicBoot();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('secret resolver registry is frozen');
        $kernel->publicSecretRegistry()->allow(
            'synthetic-vault',
            'waaseyaa/foundation',
            SecretClass::ApplicationMaster,
            'waaseyaa.application.master.v1',
            ['testing'],
        );
    }

    #[Test]
    public function injected_logger_receives_resolved_values_only_after_kernel_sink_sanitization(): void
    {
        $collector = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $kernel = new class ($this->projectRoot, $collector) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            public function resolveAndLogSyntheticValue(): void
            {
                $value = $this->secretResolverRegistry()->resolve(
                    SecretReference::create(
                        'synthetic-vault',
                        'tenant/example/kernel-composition',
                        SecretClass::ProviderCredential,
                        'waaseyaa.kernel.composition.v1',
                    ),
                    'waaseyaa/foundation',
                );
                $this->logger->info('resolved=' . SecretResolverCompositionProvider::CANARY);
                unset($value);
            }

            protected function compileManifest(): void
            {
                $this->manifest = new PackageManifest(providers: [SecretResolverCompositionProvider::class]);
            }
        };

        $kernel->publicBoot();
        $kernel->resolveAndLogSyntheticValue();

        $this->assertContains('resolved=[REDACTED]', $collector->messages, var_export($collector->messages, true));
        $this->assertStringNotContainsString(
            SecretResolverCompositionProvider::CANARY,
            implode("\n", $collector->messages),
        );
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
    public function production_boot_requires_preflight_after_compiling_manifest_inventory(): void
    {
        $databasePath = $this->projectRoot . '/storage/production.sqlite';
        touch($databasePath);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => " . var_export($databasePath, true) . ", 'environment' => 'production'];",
        );
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('m', 32)));
        $kernel = new class ($this->projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }
        };

        try {
            $kernel->publicBoot();
            self::fail('Production boot must require the exact field-access preflight.');
        } catch (\Waaseyaa\Entity\Exception\FieldAccessActivationBlocked) {
        }

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

/** @internal Synthetic provider fixture for the kernel-to-sink composition proof. */
final class SecretResolverCompositionProvider extends ServiceProvider
{
    public const CANARY = 'cfg04-kernel-sink-canary-0001';

    public function register(): void
    {
        $registry = $this->resolve(SecretResolverRegistry::class);
        if (!$registry instanceof SecretResolverRegistry) {
            throw new \LogicException('Kernel secret registry was not composed.');
        }
        $registry->registerProvider(new class implements SecretProviderInterface {
            public function id(): string
            {
                return 'synthetic-vault';
            }

            public function resolve(SecretReference $reference): SensitiveValue
            {
                return SensitiveValue::fromBytes(
                    SecretResolverCompositionProvider::CANARY,
                    SecretClass::ProviderCredential,
                    'synthetic-v1',
                );
            }
        });
        $registry->allow(
            'synthetic-vault',
            'waaseyaa/foundation',
            SecretClass::ProviderCredential,
            'waaseyaa.kernel.composition.v1',
            ['testing'],
        );
    }
}
