<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversNothing]
final class SiteVerifierGitRoundTripTest extends TestCase
{
    #[Test]
    public function generatedVerificationSurvivesAWindowsStyleGitIndexAndCheckout(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_verify_git_' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $checkout = $root . '/checkout';
        $filesystem = new Filesystem();
        $filesystem->mkdir([$source, $checkout]);
        $framework = dirname(__DIR__, 2);
        $failure = null;

        try {
            $manifest = new SiteManifestParser()->parse(<<<'YAML'
                schema: waaseyaa.site
                version: 1
                generator_version: 1
                application:
                  name: Git round trip
                  id: git-round-trip
                  canonical_origin: {config_key: APP_URL}
                framework:
                  revision_policy: exact-lock
                  observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
                content_types:
                  - {id: page, canonical_route: '/pages/{slug}'}
                capabilities:
                  - {id: payments, state: not_needed, reason: Outside this test.}
                personal_data_stores: []
                recipes: []
                verification: {command: bin/maintenance/site-verify}
                YAML);
            $site = new SiteArtifactRenderer()->render($manifest);
            foreach ($site->artifacts as $artifact) {
                $filesystem->dumpFile($source . '/' . $artifact->path, $artifact->content);
                if (DIRECTORY_SEPARATOR === '/') {
                    $filesystem->chmod($source . '/' . $artifact->path, $artifact->mode);
                }
            }
            $this->runCommand(['git', 'init', '--quiet'], $source);
            $this->runCommand(['git', 'config', 'core.filemode', 'false'], $source);
            $this->runCommand(['git', 'config', 'core.autocrlf', 'false'], $source);
            $this->runCommand(['git', 'add', '--', '.'], $source);
            $index = $this->runCommand(['git', 'ls-files', '--stage', '--', 'bin/maintenance/site-verify'], $source);
            self::assertStringStartsWith('100644 ', $index, 'A new Windows Git entry has no executable bit.');
            self::assertSame(
                0o644,
                $site->artifacts['bin/maintenance/site-verify']->mode,
                'The generated contract must survive Windows Git without an index workaround.',
            );

            $this->runCommand(['git', 'checkout-index', '--all', '--prefix=' . str_replace('\\', '/', $checkout) . '/'], $source);
            self::assertSame(
                $site->artifacts['bin/maintenance/site-verify']->content,
                file_get_contents($checkout . '/bin/maintenance/site-verify'),
            );
            $command = [PHP_BINARY, $framework . '/vendor/bin/phpunit', '--no-configuration',
                '--bootstrap', $framework . '/vendor/autoload.php', '--no-coverage',
                $checkout . '/tests/Acceptance/SiteGoldenPathTest.php'];
            $this->runCommand($command, $checkout);

            if (DIRECTORY_SEPARATOR === '/') {
                // A substituted executable mode must still fail the real generated test.
                $filesystem->chmod($checkout . '/bin/maintenance/site-verify', 0o755);
                $substituted = new Process($command, $checkout, timeout: 30);
                $substituted->run();
                self::assertSame(1, $substituted->getExitCode(), $substituted->getOutput() . $substituted->getErrorOutput());
                self::assertStringContainsString('regular-file mode', $substituted->getOutput());
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            // Git objects are read-only on Windows; release only this owned fixture.
            try {
                $filesystem->chmod($root, 0o700, recursive: true);
                $filesystem->remove($root);
            } catch (\Throwable $cleanupFailure) {
                if ($failure === null) {
                    throw $cleanupFailure;
                }
            }
        }
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $directory): string
    {
        $process = new Process($command, $directory, timeout: 30);
        $process->mustRun();

        return $process->getOutput();
    }
}
