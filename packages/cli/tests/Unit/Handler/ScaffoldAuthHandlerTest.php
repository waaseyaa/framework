<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\ScaffoldAuthHandler;
use Waaseyaa\CLI\Provider\OtherScaffoldsServiceProvider;
use Waaseyaa\CLI\Scaffold\AuthUiScaffoldManager;
use Waaseyaa\CLI\Scaffold\CliInstallPathResolverInterface;
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

    /**
     * Superseded by the #2833 repair design (2026-09-05 review of candidate
     * 290d064): the removed sibling `dirname($cliRoot) . '/admin/app'` guess
     * only ever worked when `vendor/waaseyaa/cli` was a path-repo symlink back
     * into this monorepo, which no real consumer install produces. The
     * replacement candidates are package-owned: (c)
     * {@see resolvesAuthUiSourcesFromAnInjectedInstallPathResolver} and (d)
     * {@see fallsBackToTheLoadedCliPackagesOwnResourcesDirectoryWhenNoInstallPathIsResolved}.
     */
    #[Test]
    public function resolvesAuthUiSourcesFromAnInjectedInstallPathResolver(): void
    {
        // Isolate the #2833 path: empty consumer root, no in-tree packages/,
        // and no installed waaseyaa/framework aggregate. The package-owned
        // candidate (c) is driven entirely by an injected resolver so this
        // test never touches the real vendor/ install of this repository.
        (new Filesystem())->remove($this->tempDir . '/packages');
        unlink($this->tempDir . '/VERSION');
        self::assertDirectoryDoesNotExist($this->tempDir . '/vendor/waaseyaa/framework');

        $fixtureCliRoot = $this->tempDir . '/fixture-cli-install';
        mkdir($fixtureCliRoot . '/resources/auth-ui/pages', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/components/auth', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/composables', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/assets', 0755, true);
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/pages/login.vue', '<template>fixture login</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/components/auth/LoginForm.vue', '<template>fixture form</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/components/auth/BrandPanel.vue', '<template>fixture brand</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/assets/auth.css', ':root {}');
        file_put_contents($fixtureCliRoot . '/VERSION', 'v9.9.9-fixture');

        $manager = new AuthUiScaffoldManager($this->tempDir, $this->fixedResolver($fixtureCliRoot));
        $candidates = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceCandidates')->invoke($manager);
        self::assertIsArray($candidates);

        $expectedSourceBase = $fixtureCliRoot . '/resources/auth-ui';
        $packageOwnedOnly = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => $candidate['source_base'] === $expectedSourceBase,
        ));
        self::assertNotSame([], $packageOwnedOnly, 'An injected install path must nominate the package-owned resources/auth-ui candidate.');

        $context = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceContextFromCandidates')
            ->invoke($manager, $packageOwnedOnly);
        self::assertSame($expectedSourceBase, $context['source_base']);
        self::assertSame('v9.9.9-fixture', $context['framework_version']);

        // End-to-end through the public API: publish() must resolve from the
        // injected fixture, not the real repo's own resources/auth-ui.
        $result = $manager->publish(force: false, dryRun: false);
        self::assertSame(5, $result['copied']);
        self::assertSame(
            '<template>fixture login</template>',
            file_get_contents($this->tempDir . '/app/pages/login.vue'),
        );
    }

    #[Test]
    public function fallsBackToTheLoadedCliPackagesOwnResourcesDirectoryWhenNoInstallPathIsResolved(): void
    {
        // The (c) candidate must be skipped — not merely absent — when the
        // resolver has no record of the package, falling through to (d): the
        // loaded-package-local resources/auth-ui shipped beside this class
        // file (see CliAuthUiResourceParityTest for the mirror it reads).
        (new Filesystem())->remove($this->tempDir . '/packages');
        unlink($this->tempDir . '/VERSION');

        $manager = new AuthUiScaffoldManager($this->tempDir, $this->fixedResolver(null));
        $candidates = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceCandidates')->invoke($manager);

        $packageOwnedCandidateCount = count(array_filter(
            $candidates,
            static fn(array $candidate): bool => str_ends_with($candidate['source_base'], '/resources/auth-ui'),
        ));
        self::assertSame(
            1,
            $packageOwnedCandidateCount,
            'A resolver with no install path must not push the (c) package-owned candidate; only the (d) loaded-package-local fallback should remain.',
        );

        $loadedCliRoot = dirname((string) (new \ReflectionClass(AuthUiScaffoldManager::class))->getFileName(), 3);
        $context = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceContextFromCandidates')
            ->invoke($manager, $candidates);
        self::assertSame($loadedCliRoot . '/resources/auth-ui', $context['source_base']);
    }

    #[Test]
    public function skipsAnInjectedInstallPathWhoseResourcesDirectoryDoesNotExist(): void
    {
        // (c) is nominated but must be skipped by sourceContextFromCandidates'
        // is_dir() guard when the resolved install path has no
        // resources/auth-ui at all, falling through to (d).
        (new Filesystem())->remove($this->tempDir . '/packages');
        unlink($this->tempDir . '/VERSION');

        $emptyCliRoot = $this->tempDir . '/fixture-cli-without-resources';
        mkdir($emptyCliRoot, 0755, true);

        $manager = new AuthUiScaffoldManager($this->tempDir, $this->fixedResolver($emptyCliRoot));
        $context = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceContextFromCandidates')
            ->invoke($manager, new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceCandidates')->invoke($manager));

        $loadedCliRoot = dirname((string) (new \ReflectionClass(AuthUiScaffoldManager::class))->getFileName(), 3);
        self::assertSame($loadedCliRoot . '/resources/auth-ui', $context['source_base']);
    }

    #[Test]
    public function publishFailsSafelyWhenAPackageOwnedResourceFileIsUnreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Cannot exercise an unreadable-file failure while running as root.');
        }

        (new Filesystem())->remove($this->tempDir . '/packages');
        unlink($this->tempDir . '/VERSION');

        $fixtureCliRoot = $this->tempDir . '/fixture-cli-corrupt';
        mkdir($fixtureCliRoot . '/resources/auth-ui/pages', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/components/auth', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/composables', 0755, true);
        mkdir($fixtureCliRoot . '/resources/auth-ui/assets', 0755, true);
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/pages/login.vue', '<template>fixture login</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/components/auth/LoginForm.vue', '<template>fixture form</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/components/auth/BrandPanel.vue', '<template>fixture brand</template>');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/composables/useAuth.ts', 'export function useAuth() {}');
        file_put_contents($fixtureCliRoot . '/resources/auth-ui/assets/auth.css', ':root {}');
        file_put_contents($fixtureCliRoot . '/VERSION', 'v9.9.9-fixture');
        chmod($fixtureCliRoot . '/resources/auth-ui/pages/login.vue', 0000);

        try {
            $manager = new AuthUiScaffoldManager($this->tempDir, $this->fixedResolver($fixtureCliRoot));

            // is_file() ignores permissions, so the unreadable file is still
            // nominated for publication; PHP's copy() then fails, and
            // AuthUiScaffoldManager::publish() must fail closed rather than
            // record a manifest entry or leave a partial destination file.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unable to copy auth UI file: pages/login.vue');

            try {
                // Suppress PHP's own copy() warning for the expected failure;
                // the RuntimeException assertion above is the actual behavioural
                // contract under test, not the raw engine warning.
                @$manager->publish(force: false, dryRun: false);
            } finally {
                self::assertFileDoesNotExist($this->tempDir . '/app/pages/login.vue');
            }
        } finally {
            chmod($fixtureCliRoot . '/resources/auth-ui/pages/login.vue', 0644);
        }
    }

    #[Test]
    public function throwsWhenNoCandidateSourceDirectoryExistsAnywhere(): void
    {
        $manager = new AuthUiScaffoldManager($this->tempDir);
        $fromCandidates = new \ReflectionMethod(AuthUiScaffoldManager::class, 'sourceContextFromCandidates');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Framework auth UI sources were not found in the project (packages/admin/app), an installed '
            . 'waaseyaa/framework package (vendor/waaseyaa/framework/packages/admin/app), the installed '
            . 'waaseyaa/cli package (resources/auth-ui), or the loaded waaseyaa/cli package\'s own resources/auth-ui.',
        );

        $fromCandidates->invoke($manager, []);
    }

    #[Test]
    public function fallsBackToInstalledVersionsWhenNoVersionFileIsPresentInAnyCandidateRoot(): void
    {
        // Project-owned packages/admin/app is present (setUp), but the project
        // root carries no VERSION file. resolveFrameworkVersion() must walk past
        // the missing-file `continue` and resolve the version from Composer's
        // InstalledVersions metadata for waaseyaa/framework instead of failing.
        unlink($this->tempDir . '/VERSION');
        self::assertFileDoesNotExist($this->tempDir . '/VERSION');

        $manager = new AuthUiScaffoldManager($this->tempDir);
        $resolveVersion = new \ReflectionMethod(AuthUiScaffoldManager::class, 'resolveFrameworkVersion');
        $resolved = $resolveVersion->invoke($manager, [$this->tempDir]);

        self::assertNotSame('', $resolved);
        self::assertSame(InstalledVersions::getPrettyVersion('waaseyaa/framework'), $resolved);

        $check = $this->makeTester();
        $check->execute(['--check']);
        self::assertSame(0, $check->getExitCode(), $check->getStderr() . $check->getStdout());

        $publish = $this->makeTester();
        $publish->execute([]);
        self::assertSame(0, $publish->getExitCode(), $publish->getStderr() . $publish->getStdout());
        $manifest = json_decode(
            (string) file_get_contents($this->tempDir . '/app/.waaseyaa/scaffold-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            InstalledVersions::getPrettyVersion('waaseyaa/framework'),
            $manifest['scaffolds']['auth-ui']['files']['pages/login.vue']['framework_version'],
        );
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

    /**
     * An install-path resolver whose result is fixed at construction time, so
     * tests can drive AuthUiScaffoldManager's package-owned candidate (c)
     * without touching the real Composer\InstalledVersions registration of
     * this repository's own vendor/ install (#2833 repair design, decision 3).
     */
    private function fixedResolver(?string $installPath): CliInstallPathResolverInterface
    {
        return new class ($installPath) implements CliInstallPathResolverInterface {
            public function __construct(private readonly ?string $installPath) {}

            public function resolve(): ?string
            {
                return $this->installPath;
            }
        };
    }
}
