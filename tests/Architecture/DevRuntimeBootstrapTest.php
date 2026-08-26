<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Waaseyaa\Tooling\DevRuntime;

#[CoversNothing]
final class DevRuntimeBootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/tools/lib/DevRuntime.php';
    }

    #[Test]
    public function the_manifest_pins_managed_artifacts_without_copying_the_frankenphp_pin(): void
    {
        $manifest = DevRuntime::loadManifest($this->root . '/tools/dev-runtime-manifest.json', $this->root);

        self::assertSame(1, $manifest['schema_version']);
        self::assertSame('wsl2-ubuntu-24.04-x86_64', $manifest['profile']);
        self::assertSame('v24.20.0', $manifest['tools']['node']['version']);
        self::assertSame('2.10.2', $manifest['tools']['composer']['version']);
        self::assertSame('tools/frankenphp-runtime-pin.json', $manifest['tools']['frankenphp']['pin']);
        foreach (['node', 'composer', 'frankenphp'] as $tool) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $manifest['resolved_tools'][$tool]['sha256']);
            self::assertStringStartsWith('https://', $manifest['resolved_tools'][$tool]['url']);
        }
        self::assertSame(
            '89af8424dd53e560b1933f87ba650d8bf57c83ca5a04600eefb31f416aabbae7',
            $manifest['resolved_tools']['node']['installed']['node'],
        );
    }

    #[Test]
    public function archive_entries_are_confined_to_the_declared_node_root(): void
    {
        DevRuntime::assertArchiveEntriesSafe(
            [
                'node-v24.20.0-linux-x64/',
                'node-v24.20.0-linux-x64/bin/node',
                'node-v24.20.0-linux-x64/bin/npm',
            ],
            'node-v24.20.0-linux-x64',
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('escapes declared root');
        DevRuntime::assertArchiveEntriesSafe(['node-v24.20.0-linux-x64/../../payload'], 'node-v24.20.0-linux-x64');
    }

    #[Test]
    public function system_prerequisites_fail_closed_with_one_repair_action(): void
    {
        $errors = DevRuntime::systemErrors([
            'os' => '24.04',
            'architecture' => 'x86_64',
            'wsl' => true,
            'php' => '8.4.9',
            'sqlite' => '3.45.1',
            'extensions' => ['json', 'pdo_sqlite'],
            'commands' => ['tar' => true, 'xz' => false],
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('PHP 8.5', implode("\n", $errors));
        self::assertStringContainsString('sqlite3', implode("\n", $errors));
        self::assertStringContainsString('xz', implode("\n", $errors));
        self::assertSame(
            'Provision the supported WSL2 Ubuntu 24.04 PHP 8.5 CLI prerequisites, then rerun `bin/dev-runtime bootstrap`.',
            DevRuntime::repairAction(),
        );
    }

    #[Test]
    public function cache_identity_is_manifest_addressed_and_corruption_is_detected(): void
    {
        $manifestBytes = "{\"schema_version\":1}\n";
        $root = '/tmp/runtime-cache';
        self::assertSame(
            $root . '/waaseyaa/dev-runtime/' . hash('sha256', $manifestBytes),
            DevRuntime::cachePath($root, $manifestBytes),
        );

        $directory = sys_get_temp_dir() . '/waaseyaa-dev-runtime-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);
        try {
            file_put_contents($directory . '/node', 'tampered');
            $errors = DevRuntime::artifactErrors($directory, [
                'node' => ['path' => 'node', 'sha256' => hash('sha256', 'expected')],
            ]);
            self::assertSame(['managed artifact node checksum mismatch'], $errors);
        } finally {
            unlink($directory . '/node');
            rmdir($directory);
        }
    }

    #[Test]
    public function runtime_state_cannot_replace_manifest_authority(): void
    {
        $manifest = DevRuntime::loadManifest($this->root . '/tools/dev-runtime-manifest.json', $this->root);
        $state = [
            'schema_version' => 1,
            'manifest_sha256' => $manifest['manifest_sha256'],
            'versions' => [
                'node' => 'v24.20.0',
                'composer' => '2.10.2',
                'frankenphp' => 'v1.12.4',
            ],
            'artifacts' => DevRuntime::expectedArtifacts($manifest),
        ];
        self::assertSame([], DevRuntime::stateErrors($state, $manifest));

        $state['artifacts']['node']['sha256'] = hash('sha256', 'tampered');
        self::assertSame(
            ['managed runtime state artifact inventory differs from the manifest'],
            DevRuntime::stateErrors($state, $manifest),
        );
    }

    #[Test]
    public function child_environment_prepends_only_the_verified_cache_bin(): void
    {
        $parent = ['PATH' => '/ambient/bin:/usr/bin', 'KEEP' => 'yes'];
        $child = DevRuntime::childEnvironment($parent, '/cache/runtime/bin');

        self::assertSame('/cache/runtime/bin:/ambient/bin:/usr/bin', $child['PATH']);
        self::assertSame('yes', $child['KEEP']);
        self::assertSame('/ambient/bin:/usr/bin', $parent['PATH']);
    }

    #[Test]
    public function managed_child_commands_resolve_to_verified_cache_paths(): void
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-dev-runtime-bin-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);
        file_put_contents($directory . '/node', "#!/bin/sh\n");
        chmod($directory . '/node', 0o700);
        try {
            self::assertSame(
                [$directory . '/node', '--version'],
                DevRuntime::resolveChildCommand(['node', '--version'], $directory),
            );
            self::assertSame(
                ['php', '-v'],
                DevRuntime::resolveChildCommand(['php', '-v'], $directory),
            );
            self::assertSame(
                ['./vendor/bin/phpunit'],
                DevRuntime::resolveChildCommand(['./vendor/bin/phpunit'], $directory),
            );
        } finally {
            unlink($directory . '/node');
            rmdir($directory);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('managed command composer is missing');
        DevRuntime::resolveChildCommand(['composer', '--version'], $directory);
    }

    #[Test]
    public function command_and_contract_use_the_shared_runtime_boundary(): void
    {
        self::assertFileExists($this->root . '/bin/dev-runtime');
        self::assertTrue(is_executable($this->root . '/bin/dev-runtime'));
        $command = (string) file_get_contents($this->root . '/bin/dev-runtime');
        $contract = (string) file_get_contents($this->root . '/bin/check-support-contract');
        self::assertStringContainsString('DevRuntime::captureSystem()', $command);
        self::assertStringContainsString('DevRuntime::captureSystem()', $contract);
        self::assertStringContainsString('DevRuntime::loadManifest(', $contract);
        self::assertStringContainsString('bootstrap|doctor|exec', $command);
        self::assertStringNotContainsString('sudo ', $command);
        self::assertStringNotContainsString('get.frankenphp.dev', $command);
    }
}
