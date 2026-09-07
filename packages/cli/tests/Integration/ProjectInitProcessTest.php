<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\ProjectInitHandler;
use Waaseyaa\CLI\ProjectInit\ProcOpenProjectInitProcessRunner;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\CanonicalJson;

/**
 * Process-level proofs for project:init composition.
 *
 * Requires candidate-local vendor dependencies and kernel wiring for the
 * full fresh-lifecycle case; those proofs remain integration-owner scope.
 */
#[CoversNothing]
final class ProjectInitProcessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_project_init_process_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700, true);
        file_put_contents($this->root . '/composer.json', '{"name":"test/project-init"}');
        mkdir($this->root . '/vendor/bin', 0o700, true);
        file_put_contents($this->root . '/vendor/autoload.php', '<?php');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function dryRunUsesRealProcOpenAgainstStubEntrypointWithoutCreatingDatabaseSidecar(): void
    {
        $this->installStubEntrypoint(<<<'PHP'
<?php
$command = $argv[1] ?? '';
if ($command === 'site:init') {
    fwrite(STDOUT, json_encode(['evaluation' => null, 'result' => null, 'receipts' => [], 'errors' => []], JSON_THROW_ON_ERROR));
    exit(0);
}
fwrite(STDERR, "install must not run\n");
exit(9);
PHP);

        $siteStdout = '{"evaluation":null,"result":null,"receipts":[],"errors":[]}';
        $sitePayload = json_decode($siteStdout, false, 512, JSON_THROW_ON_ERROR);
        $expected = CanonicalJson::encode([
            'schema' => 'waaseyaa.project_init_result',
            'version' => 1,
            'status' => 'previewed',
            'phases' => [
                'site' => [
                    'command' => 'site:init',
                    'status' => 'succeeded',
                    'exit_code' => 0,
                    'result' => $sitePayload,
                    'stderr' => '',
                ],
                'install' => [
                    'command' => 'install:init',
                    'status' => 'not_run',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '',
                    'not_run_reason' => 'dry_run',
                ],
            ],
            'errors' => [],
        ]) . "\n";

        $tester = $this->tester()->execute(['--answers=answers.yaml', '--dry-run', '--json']);

        self::assertSame(0, $tester->getExitCode());
        self::assertFileDoesNotExist($this->root . '/storage/waaseyaa.sqlite');
        self::assertFileDoesNotExist($this->root . '/database.sqlite');
        self::assertSame($expected, $tester->getStdout());
    }

    #[Test]
    public function fullCompositionRunsSiteThenInstallThroughInstalledEntrypoint(): void
    {
        $this->installStubEntrypoint(<<<'PHP'
<?php
$command = $argv[1] ?? '';
if ($command === 'site:init') {
    mkdir(getcwd() . '/.waaseyaa', 0o700, true);
    file_put_contents(getcwd() . '/.waaseyaa/site.yaml', "initialized: true\n");
    fwrite(STDOUT, json_encode(['evaluation' => null, 'result' => ['outcome' => 'applied'], 'receipts' => [], 'errors' => []], JSON_THROW_ON_ERROR));
    exit(0);
}
if ($command === 'install:init') {
    file_put_contents(getcwd() . '/storage/installed.marker', 'yes');
    fwrite(STDOUT, "Installation is complete.\n");
    exit(0);
}
fwrite(STDERR, "unknown command\n");
exit(2);
PHP);
        mkdir($this->root . '/storage', 0o700, true);

        $tester = $this->tester()->execute(['--answers=answers.yaml', '--yes', '--json']);

        self::assertSame(0, $tester->getExitCode());
        self::assertFileExists($this->root . '/.waaseyaa/site.yaml');
        self::assertFileExists($this->root . '/storage/installed.marker');
        $decoded = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('completed', $decoded['status']);
        self::assertSame('succeeded', $decoded['phases']['install']['status']);
        self::assertStringContainsString('Installation is complete.', $decoded['phases']['install']['stdout']);
    }

    #[Test]
    public function realInstalledCliPreservesTheDirectSiteEvaluationWithoutMaterializingState(): void
    {
        $sourceRoot = dirname(__DIR__, 4);
        self::assertTrue(copy($sourceRoot . '/packages/cli/bin/waaseyaa', $this->root . '/vendor/bin/waaseyaa'));
        // This is a source-bound integration consumer, not a packaged-install proof.
        file_put_contents($this->root . '/vendor/autoload.php', '<?php return require ' . var_export($sourceRoot . '/vendor/autoload.php', true) . ';');
        file_put_contents($this->root . '/composer.lock', "{}\n");
        file_put_contents($this->root . '/answers.yaml', <<<'YAML'
schema: waaseyaa.site
version: 1
generator_version: 1
application:
  id: example
  name: Example
  canonical_origin: {config_key: APP_ORIGIN}
framework:
  revision_policy: exact-lock
  observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
content_types:
  - {id: page, canonical_route: '/{slug}'}
capabilities:
  - id: publishing
    state: active
    package: waaseyaa/publishing
    provider: site.publishing
    configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
    public_routes: []
    data_classification: public
    lifecycle: [create, publish]
    verification: [tests/Acceptance/SiteGoldenPathTest.php]
personal_data_stores: []
recipes: []
verification: {command: bin/maintenance/site-verify}
YAML);
        $runner = new ProcOpenProjectInitProcessRunner(maxRuntimeSeconds: 10.0);
        $entrypoint = $this->root . '/vendor/bin/waaseyaa';
        $arguments = ['--answers', 'answers.yaml', '--project-root', $this->root, '--dry-run', '--json', '--no-interaction'];
        $direct = $runner->run([PHP_BINARY, $entrypoint, 'site:init', ...$arguments], $this->root, true);
        self::assertSame(0, $direct->exitCode, $direct->stderr . $direct->stdout);
        $composed = $runner->run([PHP_BINARY, $entrypoint, 'project:init', ...$arguments], $this->root, true);
        self::assertSame(0, $composed->exitCode, $composed->stderr . $composed->stdout);

        $directDocument = json_decode($direct->stdout, true, flags: JSON_THROW_ON_ERROR);
        $composedDocument = json_decode($composed->stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertNotEmpty($directDocument['evaluation']['plan']['artifacts']);
        self::assertSame(
            CanonicalJson::encode($directDocument['evaluation']),
            CanonicalJson::encode($composedDocument['phases']['site']['result']['evaluation']),
        );
        self::assertSame('previewed', $composedDocument['status']);
        self::assertSame('not_run', $composedDocument['phases']['install']['status']);
        self::assertSame('dry_run', $composedDocument['phases']['install']['not_run_reason']);
        self::assertDirectoryDoesNotExist($this->root . '/.waaseyaa');
        self::assertDirectoryDoesNotExist($this->root . '/storage');
    }

    private function tester(): CliTester
    {
        $handler = new ProjectInitHandler($this->root, new ProcOpenProjectInitProcessRunner(maxRuntimeSeconds: 5.0));
        $command = new HandlerCommand(
            name: 'project:init',
            description: 'integration probe',
            handler: \Closure::fromCallable([$handler, 'execute']),
            options: [
                new HandlerOption('answers', mode: HandlerOptionMode::Required),
                new HandlerOption('decision-receipt', mode: HandlerOptionMode::Required),
                new HandlerOption('preset', mode: HandlerOptionMode::Required),
                new HandlerOption('project-root', mode: HandlerOptionMode::Required),
                new HandlerOption('dry-run', mode: HandlerOptionMode::None),
                new HandlerOption('json', mode: HandlerOptionMode::None),
                new HandlerOption('yes', shortcut: 'y', mode: HandlerOptionMode::None),
            ],
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Unexpected service: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($command, $container);
    }

    private function installStubEntrypoint(string $body): void
    {
        $path = $this->root . '/vendor/bin/waaseyaa';
        file_put_contents($path, "#!/usr/bin/env php\n" . $body);
        chmod($path, 0o755);
    }
}
