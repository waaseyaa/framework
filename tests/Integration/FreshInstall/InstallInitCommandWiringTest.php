<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FreshInstall;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\CLI\Handler\InstallInitHandler;
use Waaseyaa\CLI\Provider\MigrateServiceProvider;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * `install:init` is reachable and constructible on a real kernel (#2428).
 *
 * MigrateServiceProvider binds InstallInitHandler through a closure that
 * composes six collaborators — the migration runtime, the entity type manager,
 * the database, the ordinary activator, the genesis seam, and the authority
 * context. Nothing short of a real boot proves that composition: the closure
 * only runs when the container is asked for the handler, and the handler's
 * first constructor parameter is an untyped `callable`, so reflection
 * auto-wiring cannot substitute for it. If any one of those resolutions
 * regresses, `install:init` fails on a fresh site — the one moment nobody is
 * watching, and the only moment the command runs.
 *
 * ## Why this test carries CoversClass and not CoversNothing
 *
 * CLAUDE.md's convention is CoversNothing for integration tests, and this is
 * a deliberate, narrow exception rather than a drift from it.
 *
 * `ci/coverage` runs `bin/check-changed-php-coverage --threshold=80`: at least
 * 80% of the executable changed lines in a PR must be covered by the merged
 * Clover report. CoversNothing tells PHPUnit to record no coverage at all, so a
 * test carrying it contributes zero to that number no matter how faithfully it
 * exercises the changed code. The binding closure is 30 executable changed
 * lines and no unit test can enter it. Measured on the branch that introduced
 * install:init: 7/30 on that file and 91/114 (79.82%) overall — a red gate over
 * a green suite. The gate is not wrong there; it is blind, and the ways to make
 * it green without this test are to fake the wiring in a unit test, extract a
 * factory that exists only to be coverable, or lower the threshold. None of
 * those improve the proof. Recording real coverage from the real composition
 * does.
 *
 * ## Why it stops at construction
 *
 * Executing the installation is proved end-to-end by
 * `tests/PackagedForm/check-fresh-install-boot`, which installs a disposable
 * consumer from the candidate tree and runs `waaseyaa install:init` twice
 * against it (fresh, then idempotent re-run). Repeating that here would
 * duplicate a slower, more faithful proof. This test answers the narrower
 * question that proof cannot isolate: is the command wired.
 */
#[CoversClass(MigrateServiceProvider::class)]
final class InstallInitCommandWiringTest extends TestCase
{
    private string $repoRoot;

    private string $databasePath;

    private string|false $originalAppEnv;

    private string|false $originalDatabase;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->databasePath = sys_get_temp_dir() . '/waaseyaa-install-init-wiring-' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalDatabase = getenv('WAASEYAA_DB');

        // Disposable: the kernel opens this path instead of the repository's own
        // storage/waaseyaa.sqlite, so the run leaves no state behind. `local`
        // keeps the boot free of the production application-secret requirement;
        // the bindings under test are environment-independent.
        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $this->databasePath);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('APP_ENV', $this->originalAppEnv);
        $this->restoreEnvironment('WAASEYAA_DB', $this->originalDatabase);

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    #[Test]
    public function the_real_container_composes_the_install_init_handler_and_registers_its_command(): void
    {
        $kernel = new ConsoleKernel($this->repoRoot);
        // Exactly the path ConsoleKernel::handle() takes for `install:init`:
        // restricted discovery, so no runtime consumer of the generation the
        // command is about to create is ever constructed.
        $kernel->bootForSchemaSync();

        $container = $kernel->buildHandlerContainer();

        $handler = $container->get(InstallInitHandler::class);

        self::assertInstanceOf(
            InstallInitHandler::class,
            $handler,
            'install:init must be constructible from the real provider closure.',
        );
        self::assertSame(
            $handler,
            $container->get(InstallInitHandler::class),
            'The binding is a singleton, so a command dispatch never rebuilds the migration runtime.',
        );

        $application = new ConsoleApplicationFactory(
            kernel: $kernel,
            container: $container,
            providers: $kernel->getProviders(),
        )->create();

        self::assertTrue(
            $application->has('install:init'),
            'MigrateServiceProvider must publish install:init to the console application.',
        );

        $command = $application->find('install:init');

        self::assertInstanceOf(HandlerCommand::class, $command);
        self::assertSame(InstallInitHandler::class, $command->sourceClass());
        self::assertStringContainsString('initial configuration generation', $command->getDescription());
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
