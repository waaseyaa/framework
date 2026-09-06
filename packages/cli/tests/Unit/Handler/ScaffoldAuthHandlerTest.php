<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Handler\ScaffoldAuthHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(ScaffoldAuthHandler::class)]
#[CoversClass(AuthUiScaffoldManager::class)]
final class ScaffoldAuthHandlerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_scaffold_auth_test_' . uniqid();
        mkdir($this->tempDir . '/packages/admin/app/pages', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/components/auth', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/composables', 0755, true);
        mkdir($this->tempDir . '/packages/admin/app/assets', 0755, true);

        file_put_contents($this->tempDir . '/packages/admin/app/pages/login.vue', '<template>login</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/LoginForm.vue', '<template>form</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/BrandPanel.vue', '<template>brand</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($this->tempDir . '/packages/admin/app/assets/auth.css', ':root {}');
        file_put_contents($this->tempDir . '/VERSION', 'v0.1.0-alpha.299');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    private function makeDefinition(): \Waaseyaa\CLI\Command\HandlerCommand
    {
        $provider = new OtherScaffoldsServiceProvider();
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'scaffold:auth') {
                return $cmd;
            }
        }

        throw new \RuntimeException('scaffold:auth command definition not found');
    }

    private function makeContainer(string $tempDir): \Psr\Container\ContainerInterface
    {
        return new class ($tempDir) implements \Psr\Container\ContainerInterface {
            public function __construct(private readonly string $tempDir) {}

            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Container::get(%s) called unexpectedly', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function itCopiesAllAuthFiles(): void
    {
        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertFileExists($this->tempDir . '/app/pages/login.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/LoginForm.vue');
        self::assertFileExists($this->tempDir . '/app/components/auth/BrandPanel.vue');
        self::assertFileExists($this->tempDir . '/app/composables/useAuth.ts');
        self::assertFileExists($this->tempDir . '/app/assets/auth.css');

        $manifest = json_decode(
            (string) file_get_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('waaseyaa.scaffold-manifest.v2', $manifest['schema']);
        $login = $manifest['scaffolds']['auth-ui']['files']['pages/login.vue'];
        self::assertSame('pages/login.vue', $login['source']);
        self::assertSame('v0.1.0-alpha.299', $login['framework_version']);
        self::assertSame('sha256', $login['digest_algorithm']);
        self::assertSame(hash('sha256', '<template>login</template>'), $login['upstream_digest']);
        self::assertSame($login['upstream_digest'], $login['consumer_digest']);
    }

    #[Test]
    public function itSkipsExistingFilesWithoutForce(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $tester = $this->makeTester();

        $tester->execute([]);

        self::assertStringContainsString('custom', (string) file_get_contents($this->tempDir . '/app/pages/login.vue'));
        self::assertStringContainsString('SKIP', $tester->getStdout());
    }

    #[Test]
    public function dryRunDoesNotWriteFiles(): void
    {
        $tester = $this->makeTester();

        $tester->execute(['--dry-run']);

        self::assertFileDoesNotExist($this->tempDir . '/app/pages/login.vue');
        self::assertStringContainsString('login.vue', $tester->getStdout());
    }

    #[Test]
    public function checkDistinguishesEveryDriftStateWithoutOverwritingConsumerFiles(): void
    {
        $this->makeTester()->execute([]);

        $manifestPath = $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        unset($manifest['scaffolds']['auth-ui']['files']['components/auth/BrandPanel.vue']);
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        file_put_contents($this->tempDir . '/packages/admin/app/pages/login.vue', '<template>upstream login</template>');
        file_put_contents($this->tempDir . '/app/components/auth/LoginForm.vue', '<template>consumer form</template>');
        file_put_contents($this->tempDir . '/packages/admin/app/components/auth/LoginForm.vue', '<template>upstream form</template>');
        file_put_contents($this->tempDir . '/app/components/auth/BrandPanel.vue', '<template>custom brand</template>');
        file_put_contents($this->tempDir . '/app/composables/useAuth.ts', 'export function customizedAuth() {}');
        unlink($this->tempDir . '/packages/admin/app/assets/auth.css');

        $before = (string) file_get_contents($this->tempDir . '/app/components/auth/LoginForm.vue');
        $tester = $this->makeTester();
        $tester->execute(['--check']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('added              components/auth/BrandPanel.vue', $tester->getStdout());
        self::assertStringContainsString('removed            assets/auth.css', $tester->getStdout());
        self::assertStringContainsString('changed-upstream   pages/login.vue', $tester->getStdout());
        self::assertStringContainsString('changed-consumer   composables/useAuth.ts', $tester->getStdout());
        self::assertStringContainsString('conflict           components/auth/LoginForm.vue', $tester->getStdout());
        self::assertSame($before, file_get_contents($this->tempDir . '/app/components/auth/LoginForm.vue'));

        $strict = $this->makeTester();
        $strict->execute(['--check', '--strict']);
        self::assertSame(1, $strict->getExitCode());
    }

    #[Test]
    public function missingOrMalformedManifestFailsSafelyWhenPublishedFilesExist(): void
    {
        mkdir($this->tempDir . '/app/pages', 0755, true);
        file_put_contents($this->tempDir . '/app/pages/login.vue', 'custom');

        $missing = $this->makeTester();
        $missing->execute(['--check']);
        self::assertSame(1, $missing->getExitCode());
        self::assertStringContainsString('manifest is missing', $missing->getStderr());
        self::assertSame('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));

        mkdir($this->tempDir . '/app/.waaseyaa', 0755, true);
        file_put_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json', '{broken');
        $malformed = $this->makeTester();
        $malformed->execute(['--check']);
        self::assertSame(1, $malformed->getExitCode());
        self::assertStringContainsString('manifest is malformed', $malformed->getStderr());
        self::assertSame('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));

        $publish = $this->makeTester();
        $publish->execute(['--force']);
        self::assertSame(1, $publish->getExitCode());
        self::assertStringContainsString('manifest is malformed', $publish->getStderr());
        self::assertSame('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));

        file_put_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json', '[]');
        $arrayManifest = $this->makeTester();
        $arrayManifest->execute(['--check']);
        self::assertSame(1, $arrayManifest->getExitCode());
        self::assertStringContainsString('expected an object', $arrayManifest->getStderr());
        self::assertSame('custom', file_get_contents($this->tempDir . '/app/pages/login.vue'));
    }

    #[Test]
    public function acceptCurrentRecordsAReviewedManualMergeWithoutOverwritingIt(): void
    {
        $this->makeTester()->execute([]);
        file_put_contents($this->tempDir . '/packages/admin/app/pages/login.vue', '<template>upstream v2</template>');
        file_put_contents($this->tempDir . '/app/pages/login.vue', '<template>custom merged v2</template>');

        $accept = $this->makeTester();
        $accept->execute(['--accept-current']);
        self::assertSame(0, $accept->getExitCode());
        self::assertStringContainsString('Accepted current reviewed auth UI baselines', $accept->getStdout());
        self::assertSame(
            '<template>custom merged v2</template>',
            file_get_contents($this->tempDir . '/app/pages/login.vue'),
        );

        $check = $this->makeTester();
        $check->execute(['--check', '--strict']);
        self::assertSame(0, $check->getExitCode());
        self::assertStringContainsString('No auth UI scaffold drift detected.', $check->getStdout());
    }

    #[Test]
    public function resolvesAuthUiSourcesFromSymlinkedCliPackageWithoutFrameworkAggregate(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_scaffold_auth_cli_path_' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $cliPrettyVersion = '0.1.0-alpha.direct-cli-proof';

        try {
            $detachedCli = $root . '/detached/cli/src/Scaffold';
            $detachedAdmin = $root . '/detached/admin/app';
            $consumer = $root . '/consumer';
            $cliVendorLink = $consumer . '/vendor/waaseyaa/cli';
            $filesystem->mkdir([$detachedCli, $detachedAdmin . '/pages', $detachedAdmin . '/components/auth', $detachedAdmin . '/composables', $detachedAdmin . '/assets', $consumer . '/vendor/waaseyaa'], 0o700);

            $managerSource = (string) (new \ReflectionClass(AuthUiScaffoldManager::class))->getFileName();
            $managerCopy = $detachedCli . '/AuthUiScaffoldManager.php';
            $filesystem->copy($managerSource, $managerCopy);
            self::assertSame(hash_file('sha256', $managerSource), hash_file('sha256', $managerCopy));

            foreach ([
                'pages/login.vue' => '<template>login</template>',
                'components/auth/LoginForm.vue' => '<template>form</template>',
                'components/auth/BrandPanel.vue' => '<template>brand</template>',
                'composables/useAuth.ts' => 'export function useAuth() {}',
                'assets/auth.css' => ':root {}',
            ] as $relativePath => $contents) {
                file_put_contents($detachedAdmin . '/' . $relativePath, $contents);
            }

            self::assertTrue(symlink($root . '/detached/cli', $cliVendorLink));
            self::assertSame(realpath($root . '/detached/cli'), realpath($cliVendorLink));

            $installedVersionsStub = $root . '/installed_versions_stub.php';
            file_put_contents($installedVersionsStub, <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Composer;

                final class InstalledVersions
                {
                    public static function isInstalled(string $package): bool
                    {
                        return $package === 'waaseyaa/cli';
                    }

                    public static function getInstallPath(string $package): ?string
                    {
                        return $package === 'waaseyaa/cli' ? $GLOBALS['waaseyaa_cli_install_path'] : null;
                    }

                    public static function getPrettyVersion(string $package): ?string
                    {
                        return $package === 'waaseyaa/cli' ? $GLOBALS['waaseyaa_cli_pretty_version'] : null;
                    }
                }
                PHP);

            $probe = $root . '/probe.php';
            file_put_contents($probe, <<<'PHP'
                <?php
                declare(strict_types=1);

                $GLOBALS['waaseyaa_cli_install_path'] = $argv[4];
                $GLOBALS['waaseyaa_cli_pretty_version'] = $argv[5];
                require $argv[7];
                require $argv[1];
                require $argv[2];

                $consumerRoot = $argv[3];
                $expectedAdmin = $argv[6];
                $expectedVersion = $argv[5];
                $manager = new \Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager($consumerRoot);
                $sourceContext = new \ReflectionMethod($manager, 'sourceContext');
                $context = $sourceContext->invoke($manager);
                if ($context['source_base'] !== $expectedAdmin) {
                    fwrite(STDERR, 'unexpected source_base: ' . $context['source_base'] . "\n");
                    exit(10);
                }
                if ($context['framework_version'] !== $expectedVersion) {
                    fwrite(STDERR, 'unexpected framework_version: ' . $context['framework_version'] . "\n");
                    exit(11);
                }

                $inspect = $manager->inspect();
                if (($inspect['status'] ?? null) !== 'not-published') {
                    fwrite(STDERR, 'inspect status: ' . json_encode($inspect, JSON_THROW_ON_ERROR) . "\n");
                    exit(12);
                }

                $publish = $manager->publish(force: false, dryRun: false);
                if (($publish['copied'] ?? 0) !== 5) {
                    fwrite(STDERR, 'publish copied: ' . json_encode($publish, JSON_THROW_ON_ERROR) . "\n");
                    exit(13);
                }

                $manifestPath = $consumerRoot . '/app/.waaseyaa/scaffold-manifest.json';
                $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
                $recordedVersion = $manifest['scaffolds']['auth-ui']['files']['pages/login.vue']['framework_version'] ?? '';
                if ($recordedVersion !== $expectedVersion) {
                    fwrite(STDERR, 'manifest framework_version: ' . $recordedVersion . "\n");
                    exit(14);
                }

                echo json_encode([
                    'source_base' => $context['source_base'],
                    'framework_version' => $context['framework_version'],
                    'inspect_status' => $inspect['status'],
                    'copied' => $publish['copied'],
                    'manifest_version' => $recordedVersion,
                    'manager' => (new \ReflectionClass($manager))->getFileName(),
                ], JSON_THROW_ON_ERROR);
                PHP);

            $expectedAdmin = realpath($detachedAdmin);
            self::assertIsString($expectedAdmin);

            $process = new Process([
                PHP_BINARY,
                $probe,
                $managerCopy,
                dirname(__DIR__, 5) . '/vendor/autoload.php',
                $consumer,
                $cliVendorLink,
                $cliPrettyVersion,
                $expectedAdmin,
                $installedVersionsStub,
            ]);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expectedAdmin, $result['source_base']);
            self::assertSame($cliPrettyVersion, $result['framework_version']);
            self::assertSame('not-published', $result['inspect_status']);
            self::assertSame(5, $result['copied']);
            self::assertSame($cliPrettyVersion, $result['manifest_version']);
            self::assertSame(realpath($managerCopy), realpath($result['manager']));
            self::assertDirectoryDoesNotExist($consumer . '/packages');
            self::assertDirectoryDoesNotExist($consumer . '/vendor/waaseyaa/framework');
        } finally {
            $filesystem->remove($root);
        }
    }

    #[Test]
    public function failsWhenNoAuthUiSourceCandidatesExist(): void
    {
        $manager = new AuthUiScaffoldManager($this->tempDir);
        $method = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceContextFromCandidates');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Framework auth UI sources were not found');

        $method->invoke($manager, [
            [
                'source_base' => $this->tempDir . '/missing-admin/app',
                'version_roots' => [$this->tempDir],
            ],
        ]);
    }

    #[Test]
    public function failsWhenAuthUiSourcesExistWithoutVersionIdentityOutsideComposer(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_scaffold_auth_version_' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();

        try {
            $orphanAdmin = $root . '/orphan-admin';
            $filesystem->mkdir($orphanAdmin . '/pages', 0o700);
            file_put_contents($orphanAdmin . '/pages/login.vue', '<template>login</template>');

            $probe = $root . '/probe.php';
            $managerSource = (string) (new \ReflectionClass(AuthUiScaffoldManager::class))->getFileName();
            file_put_contents($probe, <<<'PHP'
                <?php
                declare(strict_types=1);

                require $argv[1];

                $manager = new \Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager($argv[2]);
                $method = new \ReflectionMethod(\Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager::class, 'sourceContextFromCandidates');

                try {
                    $method->invoke($manager, [[
                        'source_base' => $argv[3],
                        'version_roots' => [$argv[2]],
                    ]]);
                    fwrite(STDERR, "expected RuntimeException\n");
                    exit(2);
                } catch (\RuntimeException $exception) {
                    echo $exception->getMessage();
                    exit(str_contains($exception->getMessage(), 'Unable to identify the Framework version') ? 0 : 3);
                }
                PHP);

            $process = new Process([PHP_BINARY, $probe, $managerSource, $root, $orphanAdmin]);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
            self::assertStringContainsString('Unable to identify the Framework version', $process->getOutput());
        } finally {
            $filesystem->remove($root);
        }
    }

    #[Test]
    public function resolveFrameworkVersionFallsBackToInstalledVersionsWhenVersionFileMissing(): void
    {
        $manager = new AuthUiScaffoldManager($this->tempDir);
        $method = new \ReflectionMethod(AuthUiScaffoldManager::class, 'resolveFrameworkVersion');
        $versionRoot = $this->tempDir . '/versionless-consumer';

        mkdir($versionRoot, 0o755, true);

        $version = $method->invoke($manager, [$versionRoot]);

        self::assertNotSame('', $version);
    }

    #[Test]
    public function resolvesFrameworkSourcesFromAConsumerVendorInstall(): void
    {
        rename($this->tempDir . '/packages', $this->tempDir . '/vendor-packages');
        mkdir($this->tempDir . '/vendor/waaseyaa/framework', 0755, true);
        rename(
            $this->tempDir . '/vendor-packages',
            $this->tempDir . '/vendor/waaseyaa/framework/packages',
        );
        rename($this->tempDir . '/VERSION', $this->tempDir . '/vendor/waaseyaa/framework/VERSION');

        $tester = $this->makeTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertFileExists($this->tempDir . '/app/pages/login.vue');
        $manifest = json_decode(
            (string) file_get_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            'v0.1.0-alpha.299',
            $manifest['scaffolds']['auth-ui']['files']['pages/login.vue']['framework_version'],
        );
    }

    #[Test]
    public function legacyChecksumManifestsRemainCheckableUntilExplicitlyAccepted(): void
    {
        $legacy = [];
        foreach ([
            'pages/login.vue',
            'components/auth/LoginForm.vue',
            'components/auth/BrandPanel.vue',
            'composables/useAuth.ts',
            'assets/auth.css',
        ] as $path) {
            $destination = $this->tempDir . '/app/' . $path;
            if (!is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }
            copy($this->tempDir . '/packages/admin/app/' . $path, $destination);
            $legacy[$path] = md5_file($destination);
        }
        mkdir($this->tempDir . '/app/.waaseyaa', 0755, true);
        file_put_contents(
            $this->tempDir . '/app/.waaseyaa/scaffold-manifest.json',
            json_encode($legacy, JSON_THROW_ON_ERROR),
        );

        $check = $this->makeTester();
        $check->execute(['--check', '--strict']);
        self::assertSame(0, $check->getExitCode());
        self::assertStringContainsString('legacy checksum manifest detected', $check->getStdout());
        self::assertStringContainsString('No auth UI scaffold drift detected.', $check->getStdout());

        $accept = $this->makeTester();
        $accept->execute(['--accept-current']);
        self::assertSame(0, $accept->getExitCode());
        $manifest = json_decode(
            (string) file_get_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('waaseyaa.scaffold-manifest.v2', $manifest['schema']);
        self::assertSame(
            'sha256',
            $manifest['scaffolds']['auth-ui']['files']['pages/login.vue']['digest_algorithm'],
        );
    }

    private function makeTester(): CliTester
    {
        $handler = new ScaffoldAuthHandler($this->tempDir);

        return CliTester::for(
            new \Waaseyaa\CLI\Command\HandlerCommand(
                name: 'scaffold:auth',
                description: 'Copy framework auth UI files into your app for customization',
                options: $this->makeDefinition()->options,
                handler: \Closure::fromCallable([$handler, 'execute']),
            ),
            $this->makeContainer($this->tempDir),
        );
    }

}
