<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ConfigManifest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\ConsoleApplicationFactory;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\RefusingConfigImportPreflight;
use Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;

/**
 * `config:import` reaches the verified path on a real kernel (#2430).
 *
 * The defect was not that verification was wrong — it was that nothing composed
 * it, so every consumer received `RefusingConfigImportPreflight` and the whole
 * CFG-03 pipeline was unreachable. That is a wiring fact, and it can only be
 * proved against a real container: the unit tests construct the gate directly,
 * which is exactly the step production was missing.
 *
 * This test runs on the importing side only. It authors no envelope and holds no
 * signing key, so it also pins the refusal a freshly installed site actually
 * sees — the state Sheg was in when a candidate integration found search
 * returning nothing from an unconfigured site.
 *
 * The positive path (sign on one host, import on another) needs real custody and
 * physically separate directories, and is proved by the packaged-consumer
 * evidence rather than in-process here.
 */
#[CoversNothing]
final class VerifiedConfigImportWiringTest extends TestCase
{
    private string $repoRoot;

    private string $databasePath;

    private string|false $originalAppEnv;

    private string|false $originalDatabase;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->databasePath = sys_get_temp_dir() . '/waaseyaa-import-wiring-' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->originalAppEnv = getenv('APP_ENV');
        $this->originalDatabase = getenv('WAASEYAA_DB');
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
    public function anInstalledSiteResolvesTheSignedEnvelopeGateRatherThanARefusal(): void
    {
        $this->install();

        $preflight = $this->bootedKernel()->buildHandlerContainer()->get(ConfigImportPreflightInterface::class);

        self::assertInstanceOf(
            SignedEnvelopeConfigImportPreflight::class,
            $preflight,
            'config:import must receive the verified gate, not the permanent refusal.',
        );
        self::assertNotInstanceOf(RefusingConfigImportPreflight::class, $preflight);
    }

    /**
     * The refusal a freshly installed site sees. It must name the sidecar it
     * wants and say that unsigned configuration is refused — a bare "import is
     * unavailable" is what made this defect invisible for so long.
     */
    #[Test]
    public function importRefusesActionablyWhenNoEnvelopeHasBeenAuthored(): void
    {
        $this->install();
        $kernel = $this->bootedKernel();

        $preflight = $kernel->buildHandlerContainer()->get(ConfigImportPreflightInterface::class);
        assert($preflight instanceof ConfigImportPreflightInterface);

        try {
            $preflight->assertReady([], [], false, false, false);
            self::fail('Import was authorized without a signed envelope.');
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            self::assertStringContainsString('.envelope.json', $message);
            self::assertStringContainsString('config:manifest:sign', $message);
            self::assertStringContainsString('Unsigned configuration is refused', $message);
        }
    }

    /** The authoring command is registered and reachable on a real console application. */
    #[Test]
    public function theAuthoringCommandIsRegisteredOnTheConsoleApplication(): void
    {
        $this->install();
        $kernel = $this->bootedKernel();

        $application = new ConsoleApplicationFactory(
            kernel: $kernel,
            container: $kernel->buildHandlerContainer(),
            providers: $kernel->getProviders(),
        )->create();

        self::assertTrue($application->has('config:manifest:sign'));
        self::assertTrue($application->has('config:import'));
    }

    /**
     * A site that has been installed but never imported holds the canonical
     * empty generation. Nothing about the import wiring changes that, and the
     * sync directory is never consulted as runtime state.
     */
    #[Test]
    public function installingDoesNotAuthorAnEnvelopeOrPopulateConfiguration(): void
    {
        $this->install();
        $kernel = $this->bootedKernel();

        $context = $kernel->buildHandlerContainer()->get(\Waaseyaa\Config\Authority\ConfigurationAuthorityContext::class);
        assert($context instanceof \Waaseyaa\Config\Authority\ConfigurationAuthorityContext);

        self::assertFileDoesNotExist(
            ConfigManifestEnvelopeFile::pathFor($context->syncPath),
            'install:init activates an empty generation; it never mints signing evidence.',
        );
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
