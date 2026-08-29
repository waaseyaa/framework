<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1SupportContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function the_bounded_s1_contract_matches_executable_test_points(): void
    {
        $contractPath = $this->root . '/support/s1-v1.json';
        self::assertFileExists($contractPath);
        self::assertFileExists($this->root . '/SECURITY.md');
        self::assertTrue(
            is_executable($this->root . '/bin/check-support-contract'),
            'The support contract checker must retain its executable bit.',
        );

        /** @var array<string, mixed> $contract */
        $contract = json_decode((string) file_get_contents($contractPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('s1-v1', $contract['contract_version'] ?? null);
        self::assertSame('S1', $contract['profile']['name'] ?? null);
        self::assertSame('candidate', $contract['profile']['status'] ?? null);
        self::assertSame('verified', $contract['evidence']['framework']['status'] ?? null);
        self::assertSame('pending', $contract['evidence']['consumer']['status'] ?? null);
        self::assertSame(['H1'], $contract['unsupported']['profiles'] ?? null);

        self::assertSame('>=8.5.0 <8.6.0', $contract['platform']['php']['constraint'] ?? null);
        self::assertSame('>=24.0.0 <25.0.0', $contract['platform']['node']['constraint'] ?? null);
        self::assertSame('>=3.40.0 <4.0.0', $contract['platform']['sqlite']['constraint'] ?? null);
        self::assertSame('ubuntu-24.04', $contract['platform']['framework_os']['runner'] ?? null);
        self::assertSame(['chromium', 'firefox'], $contract['platform']['browsers']['projects'] ?? null);

        /** @var array<string, mixed> $composer */
        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('>=8.5 <8.6', $composer['require']['php'] ?? null);
        /** @var array<string, mixed> $composerLock */
        $composerLock = json_decode((string) file_get_contents($this->root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($composer['require']['php'], $composerLock['platform']['php'] ?? null);

        self::assertSame('24', trim((string) file_get_contents($this->root . '/.nvmrc')));
        /** @var array<string, mixed> $adminPackage */
        $adminPackage = json_decode((string) file_get_contents($this->root . '/packages/admin/package.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('>=24 <25', $adminPackage['engines']['node'] ?? null);

        $workflow = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        self::assertSame(1, preg_match('/^  support-contract:\R(?<job>.*?)(?=^  [a-z0-9_-]+:\R|\z)/ms', $workflow, $supportJob));
        self::assertMatchesRegularExpression('/^    runs-on: ubuntu-24\.04$/m', $supportJob['job']);
        self::assertStringContainsString("php-version: '8.5'", $supportJob['job']);
        self::assertStringContainsString("node-version-file: '.nvmrc'", $supportJob['job']);
        self::assertStringContainsString('php bin/check-support-contract --ci --evidence=support-contract-evidence.json', $supportJob['job']);
        self::assertMatchesRegularExpression('/actions\/upload-artifact@[0-9a-f]{40}/', $supportJob['job']);
        self::assertStringNotContainsString('runs-on: ubuntu-latest', $workflow);
        foreach (['ci-unit-tests', 'ci-playwright-smoke', 'verify-gates'] as $jobId) {
            self::assertSame(1, preg_match('/^  ' . preg_quote($jobId, '/') . ':\R(?<job>.*?)(?=^  [a-z0-9_-]+:\R|\z)/ms', $workflow, $suiteJob), $jobId);
            self::assertMatchesRegularExpression('/^    runs-on: ubuntu-24\.04$/m', $suiteJob['job'], $jobId);
        }

        // #2644: the Windows lane is a DEVELOPMENT-host proof. It must stay
        // pinned like every other runner, and it must never grow into a serving
        // claim — support/s1-v1.json's platform.framework_os is ubuntu-24.04
        // and that is the serving runtime the S1 profile describes. A lane that
        // quietly started serving traffic would widen the support contract
        // without amending it.
        self::assertStringNotContainsString('runs-on: windows-latest', $workflow);
        self::assertSame(
            1,
            preg_match('/^  skeleton-create-project-windows:\R(?<job>.*?)(?=^  [a-z0-9_-]+:\R|\z)/ms', $workflow, $windowsJob),
        );
        self::assertMatchesRegularExpression('/^    runs-on: windows-2025$/m', $windowsJob['job']);
        self::assertMatchesRegularExpression('/^    timeout-minutes: \d+$/m', $windowsJob['job']);
        foreach (['waaseyaa serve', 'waaseyaa dev', 'frankenphp', 'playwright', 'phpunit'] as $servingClaim) {
            self::assertStringNotContainsString(
                $servingClaim,
                $windowsJob['job'],
                sprintf('The Windows development lane must make no serving claim (%s).', $servingClaim),
            );
        }

        $playwright = (string) file_get_contents($this->root . '/packages/admin/playwright.config.ts');
        self::assertStringContainsString("name: 'chromium'", $playwright);
        self::assertStringContainsString("name: 'firefox'", $playwright);
        self::assertStringNotContainsString("name: 'webkit'", $playwright);

        $security = (string) file_get_contents($this->root . '/SECURITY.md');
        self::assertStringContainsString('private vulnerability reporting', $security);
        self::assertStringContainsString('no response-time SLA', $security);
        self::assertStringContainsString('Authentication is not authorization', $security);
        self::assertStringContainsString('No additional accepted risk', $security);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->root . '/bin/check-support-contract') . ' 2>&1', $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
    }
}
