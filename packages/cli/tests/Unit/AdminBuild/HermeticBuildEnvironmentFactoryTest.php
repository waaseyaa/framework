<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildPlatform;
use Waaseyaa\CLI\AdminBuild\HermeticBuildEnvironmentFactory;

#[CoversClass(HermeticBuildEnvironmentFactory::class)]
final class HermeticBuildEnvironmentFactoryTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/waaseyaa_admin_env_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempRoot);
    }

    #[Test]
    public function linux_child_receives_only_the_closed_allowlist_and_no_parent_secret_values(): void
    {
        $bin = $this->directory('bin');
        $npm = $bin . '/npm';
        $npmCli = $bin . '/npm-cli.js';
        $node = $bin . '/node';
        file_put_contents($npmCli, "#!/usr/bin/env node\n");
        symlink($npmCli, $npm);
        file_put_contents($node, "#!/bin/sh\nexit 0\n");
        chmod($npmCli, 0700);
        chmod($node, 0700);
        $canary = 'cfg04-' . 'runtime-' . 'credential-' . bin2hex(random_bytes(8));

        $environment = new HermeticBuildEnvironmentFactory()->build(
            parent: [
                'PATH' => $bin,
                'HOME' => '/home/real-user',
                'NPM_TOKEN' => $canary,
                'WAASEYAA_APP_SECRET' => $canary,
                'AWS_SECRET_ACCESS_KEY' => $canary,
                'CI' => 'parent-controlled',
                'NUXT_PUBLIC_APP_NAME' => 'Synthetic Admin',
            ],
            workspace: $this->directory('workspace'),
            platform: AdminBuildPlatform::Linux,
            dependencyCache: $this->directory('dependency-cache'),
        );

        self::assertSame(realpath($npmCli), $environment->npmExecutable);
        self::assertSame(realpath($node), $environment->nodeExecutable);
        self::assertSame([
            'CI',
            'HOME',
            'NODE_ENV',
            'NUXT_PUBLIC_APP_NAME',
            'NUXT_TELEMETRY_DISABLED',
            'PATH',
            'TMPDIR',
            'WAASEYAA_ADMIN_BUILD_ID',
            'XDG_CACHE_HOME',
            'npm_config_audit',
            'npm_config_cache',
            'npm_config_fund',
            'npm_config_globalconfig',
            'npm_config_offline',
            'npm_config_update_notifier',
            'npm_config_userconfig',
        ], array_keys($environment->variables));
        self::assertSame('true', $environment->variables['CI']);
        self::assertSame('1', $environment->variables['NUXT_TELEMETRY_DISABLED']);
        self::assertSame('production', $environment->variables['NODE_ENV']);
        self::assertStringNotContainsString($canary, json_encode($environment->variables, JSON_THROW_ON_ERROR));
        self::assertDirectoryExists($environment->variables['HOME']);
        self::assertDirectoryExists($environment->variables['TMPDIR']);
        self::assertFileExists($environment->variables['npm_config_userconfig']);
        self::assertSame('', file_get_contents($environment->variables['npm_config_userconfig']));
    }

    #[Test]
    public function windows_child_receives_only_validated_os_values_and_disposable_profiles(): void
    {
        $bin = $this->directory('windows-bin');
        $systemRoot = $this->directory('Windows');
        $system32 = $systemRoot . '/System32';
        mkdir($system32, 0700, true);
        $npm = $bin . '/npm.CMD';
        $npmCli = $bin . '/node_modules/npm/bin/npm-cli.js';
        $node = $bin . '/node.EXE';
        $comspec = $system32 . '/cmd.exe';
        file_put_contents($npm, "@exit /b 0\r\n");
        mkdir(dirname($npmCli), 0700, true);
        file_put_contents($npmCli, 'synthetic');
        file_put_contents($node, 'synthetic');
        file_put_contents($comspec, 'synthetic');
        chmod($npm, 0700);
        chmod($node, 0700);
        chmod($comspec, 0700);

        $environment = new HermeticBuildEnvironmentFactory()->build(
            parent: [
                'PATH' => $bin,
                'PATHEXT' => '.COM;.EXE;.BAT;.CMD',
                'SystemRoot' => $systemRoot,
                'COMSPEC' => $comspec,
                'USERPROFILE' => 'C:\\Users\\real-user',
                'APPDATA' => 'C:\\Users\\real-user\\AppData\\Roaming',
                'TEMP' => 'C:\\Users\\real-user\\Temp',
            ],
            workspace: $this->directory('windows-workspace'),
            platform: AdminBuildPlatform::Windows,
            dependencyCache: $this->directory('windows-dependency-cache'),
        );

        self::assertSame(realpath($npmCli), $environment->npmExecutable);
        self::assertSame(realpath($node), $environment->nodeExecutable);
        self::assertSame([
            'APPDATA',
            'CI',
            'COMSPEC',
            'HOME',
            'LOCALAPPDATA',
            'NODE_ENV',
            'NUXT_TELEMETRY_DISABLED',
            'PATH',
            'PATHEXT',
            'SystemRoot',
            'TEMP',
            'TMP',
            'USERPROFILE',
            'WAASEYAA_ADMIN_BUILD_ID',
            'XDG_CACHE_HOME',
            'npm_config_audit',
            'npm_config_cache',
            'npm_config_fund',
            'npm_config_globalconfig',
            'npm_config_offline',
            'npm_config_update_notifier',
            'npm_config_userconfig',
        ], array_keys($environment->variables));
        foreach (['HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'TEMP', 'TMP'] as $name) {
            self::assertStringStartsWith(realpath($this->tempRoot), realpath($environment->variables[$name]));
        }
        self::assertSame(realpath($comspec), $environment->variables['COMSPEC']);
    }

    #[Test]
    public function relative_path_entries_and_non_absolute_overrides_refuse_before_launch(): void
    {
        $factory = new HermeticBuildEnvironmentFactory();

        foreach ([
            ['PATH' => 'relative/bin'],
            ['PATH' => $this->directory('bin-invalid'), 'NPM_BINARY' => 'relative/npm-bin', 'NODE_BINARY' => 'relative/node-bin'],
        ] as $parent) {
            try {
                $factory->build(
                    $parent,
                    $this->directory('invalid-' . bin2hex(random_bytes(2))),
                    AdminBuildPlatform::Linux,
                    $this->directory('invalid-cache-' . bin2hex(random_bytes(2))),
                );
                self::fail('Invalid executable authority must refuse.');
            } catch (\RuntimeException $e) {
                if (isset($parent['NPM_BINARY'])) {
                    self::assertStringNotContainsString($parent['NPM_BINARY'], $e->getMessage());
                } else {
                    self::assertNotSame('', $e->getMessage());
                }
            }
        }
    }

    private function directory(string $name): string
    {
        $path = $this->tempRoot . '/' . $name;
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
