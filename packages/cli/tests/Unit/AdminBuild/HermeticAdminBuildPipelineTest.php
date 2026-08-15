<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildArtifactScanner;
use Waaseyaa\CLI\AdminBuild\AdminBuildInputPolicy;
use Waaseyaa\CLI\AdminBuild\AdminBuildPlatform;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessRunnerInterface;
use Waaseyaa\CLI\AdminBuild\HermeticAdminBuildPipeline;
use Waaseyaa\CLI\AdminBuild\HermeticBuildEnvironmentFactory;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

#[CoversClass(HermeticAdminBuildPipeline::class)]
final class HermeticAdminBuildPipelineTest extends TestCase
{
    private string $projectRoot;
    private string $adminPath;
    private string $npm;
    private string $node;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_admin_pipeline_' . bin2hex(random_bytes(6));
        $this->adminPath = $this->projectRoot . '/packages/admin';
        $bin = $this->projectRoot . '/bin';
        mkdir($this->adminPath . '/app', 0700, true);
        mkdir($bin, 0700, true);
        $this->npm = $bin . '/npm';
        $this->node = $bin . '/node';
        file_put_contents($this->npm, "#!/bin/sh\nexit 0\n");
        file_put_contents($this->node, "#!/bin/sh\nexit 0\n");
        chmod($this->npm, 0700);
        chmod($this->node, 0700);
        file_put_contents($this->projectRoot . '/.nvmrc', "24\n");
        file_put_contents($this->adminPath . '/app/input.ts', 'export const synthetic = true');
        file_put_contents($this->adminPath . '/nuxt.config.ts', 'export default defineNuxtConfig({})');
        file_put_contents($this->adminPath . '/package.json', json_encode([
            'name' => '@waaseyaa/synthetic-admin',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->adminPath . '/package-lock.json', json_encode([
            'name' => '@waaseyaa/synthetic-admin',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => ['' => ['name' => '@waaseyaa/synthetic-admin', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function exact_lock_install_and_generate_use_one_minimal_environment_then_scan_outputs(): void
    {
        file_put_contents($this->adminPath . '/.nuxtrc', 'synthetic-local-control=true');
        $runner = new RecordingAdminBuildProcessRunner();
        $canary = 'cfg04-' . 'parent-' . 'credential-' . bin2hex(random_bytes(8));
        $pipeline = new HermeticAdminBuildPipeline(
            environmentFactory: new HermeticBuildEnvironmentFactory(),
            inputPolicy: new AdminBuildInputPolicy(),
            toolchainPolicy: new \Waaseyaa\CLI\AdminBuild\AdminBuildToolchainPolicy(),
            sourceWorkspace: new \Waaseyaa\CLI\AdminBuild\AdminBuildSourceWorkspace(),
            artifactScanner: new AdminBuildArtifactScanner(),
            outputPublisher: new \Waaseyaa\CLI\AdminBuild\AdminBuildOutputPublisher(),
            processRunner: $runner,
        );

        $report = $pipeline->run(
            projectRoot: $this->projectRoot,
            adminPath: $this->adminPath,
            parentEnvironment: [
                'PATH' => dirname($this->npm),
                'NPM_TOKEN' => $canary,
                'WAASEYAA_APP_SECRET' => $canary,
            ],
            sanitizer: new RedactorProcessor(registeredValues: [$canary]),
            stdout: static function (string $text): void {},
            stderr: static function (string $text): void {},
            platform: AdminBuildPlatform::Linux,
        );

        self::assertCount(3, $runner->calls);
        self::assertSame(['--version'], array_slice($runner->calls[0]['command'], 1));
        self::assertSame(realpath($this->node), $runner->calls[1]['command'][0]);
        self::assertSame(realpath($this->npm), $runner->calls[1]['command'][1]);
        self::assertContains('--offline', $runner->calls[1]['command']);
        self::assertSame(realpath($this->node), $runner->calls[2]['command'][0]);
        self::assertSame(realpath($this->npm), $runner->calls[2]['command'][1]);
        self::assertSame('run', $runner->calls[2]['command'][2]);
        self::assertSame($runner->calls[1]['environment'], $runner->calls[2]['environment']);
        self::assertSame(
            realpath($this->projectRoot . '/storage/framework/admin-build/npm-cache-v1'),
            $runner->calls[1]['environment']['npm_config_cache'],
        );
        self::assertNotSame($this->adminPath, $runner->calls[1]['cwd']);
        self::assertSame($runner->calls[1]['cwd'], $runner->calls[2]['cwd']);
        self::assertFalse($runner->sawNuxtRc);
        self::assertFileExists($this->adminPath . '/.output/public/index.html');
        self::assertStringNotContainsString($canary, json_encode($runner->calls, JSON_THROW_ON_ERROR));
        self::assertContains('packages/admin/.output/public/index.html', $report->files);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $report->inventoryHash);
        self::assertTrue($report->clean);
        self::assertFileDoesNotExist($runner->calls[2]['command'][6]);
    }

    #[Test]
    public function an_explicit_public_registry_authorization_retries_only_an_offline_cache_miss(): void
    {
        $runner = new CacheMissThenSuccessRunner();
        $pipeline = new HermeticAdminBuildPipeline(
            processRunner: $runner,
        );
        $stdout = '';

        $pipeline->run(
            projectRoot: $this->projectRoot,
            adminPath: $this->adminPath,
            parentEnvironment: [
                'PATH' => dirname($this->npm),
                'WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY' => '1',
            ],
            sanitizer: new RedactorProcessor(),
            stdout: static function (string $text) use (&$stdout): void { $stdout .= $text; },
            stderr: static function (string $text): void {},
            platform: AdminBuildPlatform::Linux,
        );

        self::assertCount(4, $runner->calls);
        self::assertContains('--offline', $runner->calls[1]['command']);
        self::assertContains('--registry=https://registry.npmjs.org/', $runner->calls[2]['command']);
        self::assertSame('false', $runner->calls[2]['environment']['npm_config_offline']);
        self::assertSame('https://registry.npmjs.org/', $runner->calls[2]['environment']['npm_config_registry']);
        self::assertArrayNotHasKey('WAASEYAA_ADMIN_BUILD_ALLOW_PUBLIC_REGISTRY', $runner->calls[2]['environment']);
        self::assertStringContainsString('credential-free public registry', $stdout);
    }
}

final class CacheMissThenSuccessRunner implements AdminBuildProcessRunnerInterface
{
    /** @var list<array{command: list<string>, environment: array<string, string>}> */
    public array $calls = [];

    public function run(
        array $command,
        string $cwd,
        array $environment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
    ): int {
        $this->calls[] = ['command' => $command, 'environment' => $environment];
        if ($command[1] === '--version') {
            $stdout("v24.19.0\n");

            return 0;
        }
        if (in_array('--offline', $command, true)) {
            $stderr("npm error code ENOTCACHED\n");

            return 1;
        }
        if ($command[1] === 'run') {
            mkdir($cwd . '/.output/public', 0700, true);
            file_put_contents($cwd . '/.output/public/index.html', '<main>synthetic retry</main>');
        }

        return 0;
    }
}

final class RecordingAdminBuildProcessRunner implements AdminBuildProcessRunnerInterface
{
    /** @var list<array{command: list<string>, cwd: string, environment: array<string, string>}> */
    public array $calls = [];
    public bool $sawNuxtRc = false;

    public function run(
        array $command,
        string $cwd,
        array $environment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
    ): int {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'environment' => $environment];
        $this->sawNuxtRc = $this->sawNuxtRc || file_exists($cwd . '/.nuxtrc');
        if ($command[1] === '--version') {
            $stdout("v24.19.0\n");
        } elseif (count($this->calls) === 3) {
            mkdir($cwd . '/.output/public', 0700, true);
            file_put_contents($cwd . '/.output/public/index.html', '<main>synthetic</main>');
        }

        return 0;
    }
}
