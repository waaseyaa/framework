<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\OperatorDiagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\Handler\HealthReportHandler;
use Waaseyaa\CLI\Provider\HealthSchemaServiceProvider;
use Waaseyaa\Foundation\Diagnostic\HealthCheckerInterface;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * `health:report` is constructible and executable on a real kernel (#2820).
 *
 * The first fix for #2820 bound `HealthReportHandler` in
 * `HealthSchemaServiceProvider` and proved it with a unit test whose fake
 * kernel-services bus served `HealthCheckerInterface`. The real bus did not:
 * the checker was only ever a handler-container kernel binding, so the
 * provider's factory threw "No binding registered for HealthCheckerInterface",
 * the container read that as "unbound handler" and fell through to reflection
 * auto-wiring, and every consumer application still saw
 * `Cannot auto-wire "…HealthReportHandler": unresolvable parameter "$projectRoot"`.
 * Nothing short of the real composition — the real bus, the real container,
 * the real provider — proves the binding, which is what this test does.
 *
 * ## Why this test carries CoversClass and not CoversNothing
 *
 * The same narrow exception `InstallInitCommandWiringTest` documents: the
 * kernel's `healthChecker()` accessor and the bus branch that serves it are
 * only entered through a real boot, and `ci/coverage` records nothing from a
 * CoversNothing test. Recording real coverage from the real composition is
 * the proof; faking the wiring in a unit test is what let #2820 ship twice.
 *
 * ## Why it is not the whole proof
 *
 * `tests/PackagedForm/check-cli-health-report` runs the same commands from
 * INSTALLED bytes in a disposable `waaseyaa/core` + `waaseyaa/cli` consumer
 * with no application code at all. This test answers the narrower in-tree
 * question in seconds: is the command wired through the real bus.
 */
#[CoversClass(HealthSchemaServiceProvider::class)]
#[CoversClass(AbstractKernel::class)]
#[CoversClass(ProviderRegistry::class)]
#[CoversClass(ProviderRegistryKernelServices::class)]
final class HealthReportCommandWiringTest extends TestCase
{
    private string $repoRoot;

    private string $databasePath;

    private string|false $originalAppEnv;

    private string|false $originalDatabase;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->databasePath = sys_get_temp_dir() . '/waaseyaa-health-report-wiring-' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalDatabase = getenv('WAASEYAA_DB');

        // Disposable database so the run leaves no state behind; `local`
        // keeps the boot free of the production application-secret
        // requirement. The wiring under test is environment-independent.
        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $this->databasePath);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('APP_ENV', $this->originalAppEnv);
        $this->restoreEnvironment('WAASEYAA_DB', $this->originalDatabase);

        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function the_real_bus_composes_the_health_report_handler_from_the_kernel_owned_checker(): void
    {
        $this->install();
        $kernel = $this->bootedKernel();
        $container = $kernel->buildHandlerContainer();

        $handler = $container->get(HealthReportHandler::class);

        self::assertInstanceOf(
            HealthReportHandler::class,
            $handler,
            'health:report must be constructible from the real provider closure over the real bus.',
        );
        self::assertSame(
            $handler,
            $container->get(HealthReportHandler::class),
            'The binding is a singleton.',
        );

        $projectRoot = new \ReflectionProperty(HealthReportHandler::class, 'projectRoot');
        self::assertSame(
            $this->repoRoot,
            $projectRoot->getValue($handler),
            'The project root must come from the framework composition contract, not from auto-wiring.',
        );

        // One checker: the bus (what the provider's factory resolved) and the
        // handler-container kernel binding (what `health:check` auto-wires)
        // must hand out the same kernel-owned instance.
        $checker = new \ReflectionProperty(HealthReportHandler::class, 'checker');
        self::assertSame($kernel->healthChecker(), $checker->getValue($handler));
        self::assertSame($kernel->healthChecker(), $container->get(HealthCheckerInterface::class));
    }

    #[Test]
    public function health_report_executes_on_the_real_console_application_in_both_output_modes(): void
    {
        $this->install();
        $kernel = $this->bootedKernel();

        $application = new ConsoleApplicationFactory(
            kernel: $kernel,
            container: $kernel->buildHandlerContainer(),
            providers: $kernel->getProviders(),
        )->create();

        self::assertTrue($application->has('health:report'));
        $command = $application->find('health:report');
        self::assertInstanceOf(HandlerCommand::class, $command);
        self::assertSame(HealthReportHandler::class, $command->sourceClass());

        $text = new CommandTester($command);
        self::assertSame(0, $text->execute([]), $text->getDisplay());
        self::assertStringContainsString('System Information', $text->getDisplay());
        self::assertStringContainsString('Health Checks', $text->getDisplay());
        self::assertStringNotContainsString('Cannot auto-wire', $text->getDisplay());

        $json = new CommandTester($command);
        self::assertSame(0, $json->execute(['--json' => true]), $json->getDisplay());
        $report = json_decode($json->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($report);
        self::assertSame($this->repoRoot, $report['system']['Project Root'] ?? null);
        $statuses = [];
        foreach ($report['health_checks'] as $check) {
            $statuses[$check['name']] = $check['status'];
        }
        self::assertSame('pass', $statuses['Configuration authority'] ?? null, $json->getDisplay());
    }

    private function install(): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['waaseyaa', 'install:init'];

        try {
            ob_start();
            $exitCode = new ConsoleKernel($this->repoRoot)->handle();
            $output = (string) ob_get_clean();
            self::assertSame(0, $exitCode, 'install:init failed: ' . $output);
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    private function bootedKernel(): ConsoleKernel
    {
        $kernel = new ConsoleKernel($this->repoRoot);
        $kernel->bootForCli();

        return $kernel;
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }
}
