<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the CI/release workflow invariants that cannot be exercised as PHP
 * behavior: blocking architecture coverage, immutable action references, and
 * a green-CI/checksum gate before release fan-out.
 */
#[CoversNothing]
final class CiReleaseWorkflowParityTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    #[Test]
    public function blocking_ci_runs_architecture_and_the_pl008_meta_test(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertStringContainsString('--testsuite Architecture', $ci);
        self::assertStringContainsString('check-package-layers-pl008-self-test', $ci);
    }

    #[Test]
    public function security_sensitive_php_and_release_actions_are_sha_pinned(): void
    {
        $workflows = glob($this->repoRoot . '/.github/workflows/*.yml');
        self::assertNotFalse($workflows);

        $matches = 0;
        foreach ($workflows as $workflow) {
            $contents = (string) file_get_contents($workflow);
            preg_match_all(
                '/uses:\s+(shivammathur\/setup-php|softprops\/action-gh-release)@([^\s#]+)/',
                $contents,
                $references,
                PREG_SET_ORDER,
            );

            foreach ($references as [, $action, $revision]) {
                ++$matches;
                self::assertMatchesRegularExpression(
                    '/^[0-9a-f]{40}$/',
                    $revision,
                    sprintf('%s must use an immutable full commit SHA in %s.', $action, $workflow),
                );
            }
        }

        self::assertGreaterThan(0, $matches, 'Expected to find setup-php/action-gh-release uses to audit.');
    }

    #[Test]
    public function split_fan_out_requires_green_ci_for_the_exact_tagged_sha(): void
    {
        $split = $this->read('.github/workflows/split.yml');
        $gateStart = strpos($split, '  verify-ci-green:');
        $splitStart = strpos($split, '  split:');

        self::assertNotFalse($gateStart, 'split.yml must define the fail-closed verify-ci-green job.');
        self::assertNotFalse($splitStart, 'split.yml must define the split fan-out job.');
        self::assertLessThan($splitStart, $gateStart, 'CI verification must be declared before fan-out.');

        $gate = substr($split, $gateStart, $splitStart - $gateStart);
        self::assertStringContainsString('ref: ${{ github.sha }}', $gate);
        self::assertStringContainsString('bash bin/wait-for-green-ci "${SHA}" 2700', $gate);

        $greenCiGate = $this->read('bin/wait-for-green-ci');
        self::assertStringContainsString('actions/workflows/ci.yml/runs?head_sha=${SHA}', $greenCiGate);
        self::assertStringContainsString('if [ "$conclusion" = "success" ]', $greenCiGate);
        self::assertStringContainsString('if [ "$TIMEOUT" = "0" ]', $greenCiGate);

        $splitJob = substr($split, $splitStart, 300);
        self::assertMatchesRegularExpression('/needs:\s+verify-ci-green/', $splitJob);
    }

    #[Test]
    public function splitsh_archive_is_verified_before_extraction(): void
    {
        $split = $this->read('.github/workflows/split.yml');
        $download = strpos($split, 'lite_linux_amd64.tar.gz');
        $checksum = strpos($split, '2539301ce5e21d0ca44b689d0dd2c1b20d9f9e996c1fe6c462afb8af4e7141cc');
        $verify = strpos($split, 'sha256sum --check');
        $extract = strpos($split, 'tar -xzf');

        self::assertNotFalse($download);
        self::assertNotFalse($checksum, 'The v1.0.1 Linux release archive must have its pinned SHA-256.');
        self::assertNotFalse($verify);
        self::assertNotFalse($extract);
        self::assertLessThan($verify, $checksum, 'Checksum declaration must precede verification.');
        self::assertLessThan($extract, $verify, 'Checksum verification must precede archive extraction.');
    }

    #[Test]
    public function release_commit_stages_every_file_mutated_by_internal_version_sync(): void
    {
        $release = $this->read('.github/workflows/release-cut.yml');

        self::assertStringContainsString(
            'git add CHANGELOG.md VERSION composer.lock packages/*/composer.json skeleton/composer.json',
            $release,
        );
    }

    private function read(string $relativePath): string
    {
        $path = $this->repoRoot . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
