<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Pins the skeleton's `composer regen-lock` (#2679).
 *
 * Composer runs a script line through the host shell. The skeleton's
 * `@composer update 'waaseyaa/*' ...` relied on POSIX single quotes. cmd.exe
 * does not treat single quotes as quoting, so on native Windows Composer
 * received the pattern `'waaseyaa/*'` with the quotes, matched no locked
 * package, changed nothing, and still exited 0. The pattern must reach
 * Composer as exactly `waaseyaa/*` on every host, without a POSIX shell
 * expanding it as a glob.
 *
 * The proof is behavioural and offline. A project is generated from the local
 * skeleton by a real `composer create-project`. Its own `regen-lock` then runs
 * through `composer run-script` in a fixture whose packages come only from an
 * inline repository, with Packagist disabled and isolated Composer state.
 */
#[CoversNothing]
final class SkeletonRegenLockTest extends TestCase
{
    private string $root;
    private string $scratch;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->scratch = sys_get_temp_dir() . '/waaseyaa-regen-lock-' . bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->scratch);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->scratch);
    }

    #[Test]
    public function the_generated_and_published_skeleton_inherit_the_regen_lock_script(): void
    {
        $source = $this->regenLockScript($this->root . '/skeleton/composer.json');

        self::assertSame($source, $this->regenLockScript($this->generatedProject() . '/composer.json'), 'composer create-project must carry the skeleton script verbatim.');

        $published = $this->scratch . '/published-composer.json';
        new Filesystem()->copy($this->root . '/skeleton/composer.json', $published);
        [$code, $output] = $this->execute([PHP_BINARY, $this->root . '/tools/sync-skeleton-requirements.php', $this->root . '/skeleton/composer.json', $published, '0.1.0-alpha.999'], $this->root);
        self::assertSame(0, $code, $output);
        self::assertSame($source, $this->regenLockScript($published), 'The published-skeleton sync must carry the script verbatim.');
    }

    /**
     * The generated project's own script must update exactly the locked
     * `waaseyaa/*` packages, on every host. A `waaseyaa/` directory in the
     * project would turn an unquoted pattern into a POSIX glob match. The
     * flags keep their meaning: no plugins, no vendor/ install, no scripts.
     */
    #[Test]
    public function regen_lock_updates_exactly_the_locked_waaseyaa_packages(): void
    {
        $project = $this->fixture($this->regenLockScript($this->generatedProject() . '/composer.json'));
        $this->lockAtFirstRelease($project);

        [$code, $output] = $this->composer($project, ['run-script', 'regen-lock']);

        self::assertSame(0, $code, $output);
        self::assertStringNotContainsString('does not match any locked packages', $output);
        self::assertStringContainsString('Upgrading waaseyaa/alpha (1.0.0 => 1.1.0)', $output);
        self::assertStringContainsString('Upgrading waaseyaa/gamma (1.0.0 => 1.1.0)', $output);
        self::assertSame(
            ['other/beta' => '1.0.0', 'waaseyaa/alpha' => '1.1.0', 'waaseyaa/gamma' => '1.1.0'],
            $this->lockedVersions($project),
            'Only the waaseyaa/* packages may move.',
        );
        self::assertDirectoryDoesNotExist($project . '/vendor', '--no-install writes the lock only.');
        self::assertFileDoesNotExist($project . '/post-update-ran', '--no-scripts keeps project scripts from running.');
    }

    #[Test]
    public function regen_lock_returns_composer_failure_unchanged(): void
    {
        $project = $this->fixture($this->regenLockScript($this->root . '/skeleton/composer.json'));
        $this->lockAtFirstRelease($project);
        $this->requireUnavailableAlpha($project);

        [$code, $output] = $this->composer($project, ['run-script', 'regen-lock']);

        self::assertSame(2, $code, $output);
        self::assertStringContainsString('Your requirements could not be resolved', $output);
    }

    private function generatedProject(): string
    {
        $target = $this->scratch . '/generated';
        if (is_file($target . '/composer.json')) {
            return $target;
        }
        $repository = json_encode([
            'type' => 'path',
            'url' => $this->root . '/skeleton',
            'options' => ['symlink' => false, 'versions' => ['waaseyaa/waaseyaa' => '1.0.0']],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        [$code, $output] = $this->composer($this->scratch, [
            'create-project', 'waaseyaa/waaseyaa', $target, '1.0.0', '--repository=' . $repository, '--no-install', '--no-scripts',
        ]);
        self::assertSame(0, $code, $output);

        return $target;
    }

    /**
     * A project that requires two waaseyaa/* packages and one other package,
     * each published at 1.0.0 and 1.1.0 by an inline repository only.
     */
    private function fixture(string $regenLock): string
    {
        $project = $this->scratch . '/project';
        $packages = [];
        foreach (['waaseyaa/alpha', 'waaseyaa/gamma', 'other/beta'] as $name) {
            foreach (['1.0.0', '1.1.0'] as $version) {
                $packages[] = ['name' => $name, 'version' => $version, 'type' => 'library'];
            }
        }
        $filesystem = new Filesystem();
        $filesystem->dumpFile($project . '/composer.json', json_encode([
            'name' => 'fixture/regen-lock',
            'type' => 'project',
            'require' => ['waaseyaa/alpha' => '^1.0', 'waaseyaa/gamma' => '^1.0', 'other/beta' => '^1.0'],
            'repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => $packages]],
            'scripts' => [
                'regen-lock' => $regenLock,
                'post-update-cmd' => '@php -r "touch(\'post-update-ran\');"',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $filesystem->dumpFile($project . '/waaseyaa/decoy', "A shell glob of waaseyaa/* would expand to this file.\n");

        return $project;
    }

    private function lockAtFirstRelease(string $project): void
    {
        [$code, $output] = $this->composer($project, [
            'update', '--no-install', '--no-plugins', '--no-scripts',
            '--with', 'waaseyaa/alpha:1.0.0', '--with', 'waaseyaa/gamma:1.0.0', '--with', 'other/beta:1.0.0',
        ]);
        self::assertSame(0, $code, $output);
        self::assertSame(['other/beta' => '1.0.0', 'waaseyaa/alpha' => '1.0.0', 'waaseyaa/gamma' => '1.0.0'], $this->lockedVersions($project));
    }

    private function requireUnavailableAlpha(string $project): void
    {
        $manifest = json_decode((string) file_get_contents($project . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['require']['waaseyaa/alpha'] = '^2.0';
        file_put_contents($project . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    /** @return array<string, string> */
    private function lockedVersions(string $project): array
    {
        $lock = json_decode((string) file_get_contents($project . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $versions = [];
        foreach ($lock['packages'] as $package) {
            $versions[$package['name']] = $package['version'];
        }
        ksort($versions);

        return $versions;
    }

    private function regenLockScript(string $manifest): string
    {
        $decoded = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
        $script = $decoded['scripts']['regen-lock'] ?? null;
        self::assertIsString($script, $manifest . ' must declare scripts.regen-lock.');

        return $script;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function composer(string $cwd, array $arguments): array
    {
        return $this->execute([...$this->composerCommand(), ...$arguments, '--no-ansi', '--no-interaction'], $cwd, [
            'COMPOSER' => false,
            'COMPOSER_HOME' => $this->scratch . '/.composer-home',
            'COMPOSER_CACHE_DIR' => $this->scratch . '/.composer-cache',
            'COMPOSER_NO_INTERACTION' => '1',
            // Nothing here may depend on Packagist or any other network source.
            'COMPOSER_DISABLE_NETWORK' => '1',
        ]);
    }

    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     *
     * @return array{int, string}
     */
    private function execute(array $command, string $cwd, array $environment = []): array
    {
        $process = new Process($command, $cwd, $environment === [] ? null : $environment, null, 180.0);
        $process->run();

        return [(int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
    }

    /**
     * Composer exports COMPOSER_BINARY to its scripts, so a runner started by
     * `composer test` pins the binary it runs under. That binary is a PHP
     * program (often composer.phar), which only PHP can start on Windows.
     *
     * @return non-empty-list<string>
     */
    private function composerCommand(): array
    {
        $override = getenv('COMPOSER_BINARY');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return [PHP_BINARY, $override];
        }

        $found = new ExecutableFinder()->find('composer');
        if (is_string($found) && $found !== '') {
            return [$found];
        }

        self::fail('Composer must be available to run the skeleton script it declares.');
    }
}
