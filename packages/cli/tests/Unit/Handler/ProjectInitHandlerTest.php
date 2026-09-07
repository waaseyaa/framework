<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\ProjectInitHandler;
use Waaseyaa\CLI\ProjectInit\ProjectInitProcessResult;
use Waaseyaa\CLI\ProjectInit\ProjectInitProcessRunnerInterface;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\CanonicalJson;

#[CoversClass(ProjectInitHandler::class)]
final class ProjectInitHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function exactCompositionMapsEverySupportedOptionToSiteArgvAndInstallOnlyAfterSuccess(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: '{"evaluation":null,"result":null,"receipts":[],"errors":[]}'),
            new ProjectInitProcessResult(0, stdout: 'Installation is complete.'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute([
            '--answers=answers.yaml',
            '--decision-receipt=receipt.json',
            '--preset=minimal',
            '--project-root=' . $root,
            '--yes',
            '--json',
        ]);

        self::assertSame(0, $tester->getExitCode());
        self::assertCount(2, $runner->calls);
        self::assertSame($root, $runner->calls[0]['cwd']);
        self::assertSame($root, $runner->calls[1]['cwd']);
        self::assertTrue($runner->calls[0]['capture']);
        self::assertTrue($runner->calls[1]['capture']);
        self::assertSame([
            PHP_BINARY,
            $root . '/vendor/bin/waaseyaa',
            'site:init',
            '--answers',
            'answers.yaml',
            '--decision-receipt',
            'receipt.json',
            '--preset',
            'minimal',
            '--project-root',
            $root,
            '--json',
            '--yes',
            '--no-interaction',
        ], $runner->calls[0]['command']);
        self::assertSame([
            PHP_BINARY,
            $root . '/vendor/bin/waaseyaa',
            'install:init',
            '--no-interaction',
        ], $runner->calls[1]['command']);
        self::assertStringNotContainsString('SiteInitializationService', (string) file_get_contents(__DIR__ . '/../../../src/Handler/ProjectInitHandler.php'));
    }

    #[Test]
    public function dryRunInvokesOnlySiteDryRunAndReportsInstallNotRun(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: '{"evaluation":null,"result":null,"receipts":[],"errors":[]}'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--dry-run', '--yes', '--json']);

        self::assertSame(0, $tester->getExitCode());
        self::assertCount(1, $runner->calls);
        self::assertContains('--dry-run', $runner->calls[0]['command']);
        $decoded = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('previewed', $decoded['status']);
        self::assertSame('not_run', $decoded['phases']['install']['status']);
        self::assertSame('dry_run', $decoded['phases']['install']['not_run_reason']);
    }

    #[Test]
    #[DataProvider('siteFailurePreventsInstallProvider')]
    public function siteFailuresNeverStartInstall(ProjectInitProcessResult $siteResult, int $expectedExit): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([$siteResult]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--yes', '--json']);

        self::assertCount(1, $runner->calls);
        self::assertSame($expectedExit, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $decoded['status']);
        self::assertSame('not_run', $decoded['phases']['install']['status']);
        self::assertSame('site_failed', $decoded['phases']['install']['not_run_reason']);
    }

    /**
     * @return iterable<string, array{ProjectInitProcessResult, int}>
     */
    public static function siteFailurePreventsInstallProvider(): iterable
    {
        yield 'site exit 1' => [new ProjectInitProcessResult(1, stdout: '{"errors":[{"message":"refused"}]}'), 1];
        yield 'site interrupt 130' => [new ProjectInitProcessResult(130, stdout: '{}'), 130];
        yield 'start failure' => [ProjectInitProcessResult::startFailed(), 1];
        yield 'capture failure after spawn' => [new ProjectInitProcessResult(1, errorCode: 'PROJECT_INIT007_CHILD_CAPTURE_FAILED'), 1];
        yield 'timeout' => [ProjectInitProcessResult::timedOut(), 1];
        yield 'overflow' => [ProjectInitProcessResult::outputLimitExceeded(), 1];
        yield 'cleanup failure' => [ProjectInitProcessResult::cleanupFailed(4242, 'child_not_terminal_after_escalation'), 1];
        yield 'invalid json' => [new ProjectInitProcessResult(0, stdout: 'not-json'), 1];
    }

    #[Test]
    public function installFailureReturnsObservedExitAfterSuccessfulSite(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: '{"evaluation":null,"result":null,"receipts":[],"errors":[]}'),
            new ProjectInitProcessResult(7, stdout: 'authority line', stderr: 'schema refused'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--yes', '--json']);

        self::assertSame(7, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $decoded['status']);
        self::assertSame('failed', $decoded['phases']['install']['status']);
        self::assertSame(7, $decoded['phases']['install']['exit_code']);
        self::assertSame('authority line', $decoded['phases']['install']['stdout']);
        self::assertSame('schema refused', $decoded['phases']['install']['stderr']);
    }

    #[Test]
    public function nonInteractivePlainInvocationAttachesStreamsButPassesNoInteraction(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0),
            new ProjectInitProcessResult(0),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--yes']);

        self::assertFalse($runner->calls[0]['capture']);
        self::assertContains('--no-interaction', $runner->calls[0]['command']);
        self::assertContains('--no-interaction', $runner->calls[1]['command']);
    }

    #[Test]
    public function interactivePlainInvocationDoesNotPassNoInteraction(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0),
            new ProjectInitProcessResult(0),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root, interactive: true);

        $tester->execute(['--answers=answers.yaml', '--yes']);

        self::assertFalse($runner->calls[0]['capture']);
        self::assertNotContains('--no-interaction', $runner->calls[0]['command']);
    }

    #[Test]
    public function jsonEnvelopePreservesObjectSemanticsAndMatchesCanonicalBytes(): void
    {
        $root = $this->validProjectRoot();
        $siteStdout = '{"nested":{},"numeric":{"0":"alpha"},"list":[1,2],"empty":{}}';
        $sitePayload = json_decode($siteStdout, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $sitePayload);
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: $siteStdout),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--dry-run', '--json']);

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
        self::assertSame($expected, $tester->getStdout());
        self::assertStringContainsString('"nested":{}', $tester->getStdout());
        self::assertStringContainsString('"numeric":{"0":"alpha"}', $tester->getStdout());
        self::assertStringNotContainsString('"numeric":["alpha"]', $tester->getStdout());
        self::assertStringContainsString('"empty":{}', $tester->getStdout());
    }

    #[Test]
    public function emptySiteObjectEmbedsAsJsonObjectNotArray(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(130, stdout: '{}'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--json']);

        self::assertSame(130, $tester->getExitCode());
        self::assertStringContainsString('"result":{}', $tester->getStdout());
        self::assertStringNotContainsString('"result":[]', $tester->getStdout());
    }

    #[Test]
    public function presentEmptyOptionValuesForwardVerbatimWithoutTrimming(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([new ProjectInitProcessResult(0)]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->executeMap([
            '--answers' => '',
            '--decision-receipt' => '',
            '--preset' => '',
            '--dry-run' => true,
        ]);

        self::assertSame([
            PHP_BINARY,
            $root . '/vendor/bin/waaseyaa',
            'site:init',
            '--answers',
            '',
            '--decision-receipt',
            '',
            '--preset',
            '',
            '--project-root',
            $root,
            '--dry-run',
            '--no-interaction',
        ], $runner->calls[0]['command']);
    }

    #[Test]
    public function optionValuesWithSurroundingSpacesForwardVerbatim(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([new ProjectInitProcessResult(0)]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->executeMap([
            '--answers' => ' spaced answers.yaml ',
            '--preset' => ' minimal ',
            '--decision-receipt' => ' receipt.json ',
            '--dry-run' => true,
        ]);

        $command = $runner->calls[0]['command'];
        self::assertSame(' spaced answers.yaml ', $command[4]);
        self::assertSame(' receipt.json ', $command[6]);
        self::assertSame(' minimal ', $command[8]);
    }

    #[Test]
    public function jsonModeCapturesBothStreamsAndEmbedsSiteResultUnchanged(): void
    {
        $root = $this->validProjectRoot();
        $siteStdout = '{"evaluation":{"plan_digest":"abc"},"result":{"outcome":"applied"},"receipts":[],"errors":[]}';
        $sitePayload = json_decode($siteStdout, false, 512, JSON_THROW_ON_ERROR);
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: $siteStdout, stderr: 'site stderr'),
            new ProjectInitProcessResult(0, stdout: 'install stdout', stderr: 'install stderr'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--yes', '--json']);

        $expected = CanonicalJson::encode([
            'schema' => 'waaseyaa.project_init_result',
            'version' => 1,
            'status' => 'completed',
            'phases' => [
                'site' => [
                    'command' => 'site:init',
                    'status' => 'succeeded',
                    'exit_code' => 0,
                    'result' => $sitePayload,
                    'stderr' => 'site stderr',
                ],
                'install' => [
                    'command' => 'install:init',
                    'status' => 'succeeded',
                    'exit_code' => 0,
                    'stdout' => 'install stdout',
                    'stderr' => 'install stderr',
                    'not_run_reason' => null,
                ],
            ],
            'errors' => [],
        ]) . "\n";
        self::assertSame($expected, $tester->getStdout());
    }

    #[Test]
    public function relativeProjectRootResolvesAgainstKernelRootAndRejectsInvalidLayouts(): void
    {
        $parent = $this->validProjectRoot();
        $child = $parent . '/nested';
        mkdir($child);
        file_put_contents($child . '/composer.json', '{}');
        mkdir($child . '/vendor/bin', 0o777, true);
        file_put_contents($child . '/vendor/autoload.php', '<?php');
        file_put_contents($child . '/vendor/bin/waaseyaa', '#!/usr/bin/env php');

        $runner = new RecordingProjectInitRunner([new ProjectInitProcessResult(0)]);
        $handler = new ProjectInitHandler($parent, $runner);
        $tester = $this->tester($handler, $parent);
        $tester->execute(['--answers=answers.yaml', '--project-root=nested', '--dry-run', '--json']);
        self::assertSame(realpath($child), $runner->calls[0]['cwd']);

        $missing = $this->validProjectRoot();
        unlink($missing . '/vendor/bin/waaseyaa');
        $missingRunner = new RecordingProjectInitRunner([]);
        $tester = $this->tester(new ProjectInitHandler($missing, $missingRunner), $missing);
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ProjectInitHandler::ERROR_INVALID_PROJECT_ROOT, $decoded['errors'][0]['code']);
        self::assertSame([], $missingRunner->calls);
    }

    #[Test]
    public function shellMetacharactersRemainSingleArgvMembers(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([new ProjectInitProcessResult(0)]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=has spaces & metachars.yaml', '--preset=minimal|editorial', '--dry-run']);

        $command = $runner->calls[0]['command'];
        self::assertContains('has spaces & metachars.yaml', $command);
        self::assertContains('minimal|editorial', $command);
        self::assertSame('--answers', $command[3]);
        self::assertSame('has spaces & metachars.yaml', $command[4]);
    }

    #[Test]
    public function retryRunsSiteAgainWithoutPrivateLedger(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0, stdout: '{}'),
            new ProjectInitProcessResult(1, stdout: 'failed install'),
            new ProjectInitProcessResult(0, stdout: '{}'),
            new ProjectInitProcessResult(0, stdout: 'ok'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);

        self::assertSame(1, $this->tester($handler, $root)->execute(['--answers=answers.yaml', '--yes', '--json'])->getExitCode());
        self::assertSame(0, $this->tester($handler, $root)->execute(['--answers=answers.yaml', '--yes', '--json'])->getExitCode());
        self::assertCount(4, $runner->calls);
        self::assertSame('site:init', $runner->calls[0]['command'][2]);
        self::assertSame('site:init', $runner->calls[2]['command'][2]);
        self::assertSame('install:init', $runner->calls[1]['command'][2]);
        self::assertSame('install:init', $runner->calls[3]['command'][2]);
    }

    #[Test]
    public function cleanupFailureReports006WithPidAndDiagnosticInJsonEnvelope(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            ProjectInitProcessResult::cleanupFailed(4242, 'cleanup_failed_after=RuntimeException'),
        ]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--yes', '--json']);

        self::assertSame(1, $tester->getExitCode());
        self::assertCount(1, $runner->calls);
        $expected = CanonicalJson::encode([
            'schema' => 'waaseyaa.project_init_result',
            'version' => 1,
            'status' => 'failed',
            'phases' => [
                'site' => [
                    'command' => 'site:init',
                    'status' => 'failed',
                    'exit_code' => 1,
                    'result' => new \stdClass(),
                    'stderr' => '',
                ],
                'install' => [
                    'command' => 'install:init',
                    'status' => 'not_run',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '',
                    'not_run_reason' => 'site_failed',
                ],
            ],
            'errors' => [[
                'phase' => 'site',
                'code' => ProjectInitProcessResult::ERROR_CHILD_CLEANUP_FAILED,
                'child_pid' => 4242,
                'diagnostic' => 'cleanup_failed_after=RuntimeException',
            ]],
        ]) . "\n";
        self::assertSame($expected, $tester->getStdout());
    }

    #[Test]
    public function plainInstallCleanupFailureReportsPidAndDiagnostic(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0),
            ProjectInitProcessResult::cleanupFailed(4242, 'initiating=PROJECT_INIT002_CHILD_TIMEOUT'),
        ]);
        $tester = $this->tester(new ProjectInitHandler($root, $runner), $root);
        $tester->execute(['--yes']);

        self::assertSame(1, $tester->getExitCode());
        self::assertCount(2, $runner->calls);
        self::assertStringContainsString('Child cleanup failed for pid 4242', $tester->getStderr());
        self::assertStringContainsString('PROJECT_INIT002_CHILD_TIMEOUT', $tester->getStderr());
    }

    #[Test]
    public function plainInstallChildFailurePreservesExitWithoutJsonPayload(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([
            new ProjectInitProcessResult(0),
            new ProjectInitProcessResult(130),
        ]);
        $tester = $this->tester(new ProjectInitHandler($root, $runner), $root);
        $tester->execute(['--yes']);

        self::assertSame(130, $tester->getExitCode());
        self::assertCount(2, $runner->calls);
    }

    #[Test]
    public function plainDryRunAddsCanonicalCompletionMessage(): void
    {
        $root = $this->validProjectRoot();
        $runner = new RecordingProjectInitRunner([new ProjectInitProcessResult(0)]);
        $handler = new ProjectInitHandler($root, $runner);
        $tester = $this->tester($handler, $root);

        $tester->execute(['--answers=answers.yaml', '--dry-run']);

        self::assertStringContainsString('Dry run complete; install:init was not run.', $tester->getStdout());
    }

    private function tester(ProjectInitHandler $handler, string $root, bool $interactive = false): CliTester
    {
        $command = new HandlerCommand(
            name: 'project:init',
            description: 'Compose site:init and install:init for a fresh project',
            handler: \Closure::fromCallable([$handler, 'execute']),
            options: [
                new HandlerOption('answers', mode: HandlerOptionMode::Required, description: 'Answer document forwarded to site:init'),
                new HandlerOption('decision-receipt', mode: HandlerOptionMode::Required, description: 'Decision receipt forwarded to site:init'),
                new HandlerOption('preset', mode: HandlerOptionMode::Required, description: 'Preset forwarded to site:init'),
                new HandlerOption('project-root', mode: HandlerOptionMode::Required, description: 'Application project root'),
                new HandlerOption('dry-run', mode: HandlerOptionMode::None, description: 'Preview site phase only'),
                new HandlerOption('json', mode: HandlerOptionMode::None, description: 'Emit one parent JSON document'),
                new HandlerOption('yes', shortcut: 'y', mode: HandlerOptionMode::None, description: 'Forwarded to site:init'),
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

        return CliTester::for($command, $container, $interactive ? new InteractiveCliStdin() : null);
    }

    private function validProjectRoot(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_project_init_handler_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        file_put_contents($root . '/composer.json', '{"name":"test/project"}');
        mkdir($root . '/vendor/bin', 0o777, true);
        file_put_contents($root . '/vendor/autoload.php', '<?php');
        file_put_contents($root . '/vendor/bin/waaseyaa', '#!/usr/bin/env php');
        $this->roots[] = $root;

        return $root;
    }
}

/** @internal */
final class RecordingProjectInitRunner implements ProjectInitProcessRunnerInterface
{
    /** @var list<array{command: list<string>, cwd: string, capture: bool}> */
    public array $calls = [];

    /** @param list<ProjectInitProcessResult> $responses */
    public function __construct(private array $responses) {}

    public function run(array $command, string $cwd, bool $captureOutput): ProjectInitProcessResult
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'capture' => $captureOutput];

        if ($this->responses === []) {
            return new ProjectInitProcessResult(0);
        }

        return array_shift($this->responses);
    }
}

/** @internal */
final class InteractiveCliStdin
{
    public function isInteractive(): bool
    {
        return true;
    }

    public function readLine(): ?string
    {
        return null;
    }
}
