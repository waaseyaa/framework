<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AdminTestPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_node_workflow_uses_the_repository_lts_pin(): void
    {
        self::assertSame("24\n", file_get_contents($this->root . '/.nvmrc'));

        foreach (glob($this->root . '/.github/workflows/*.yml') ?: [] as $path) {
            $workflow = (string) file_get_contents($path);
            if (!str_contains($workflow, 'actions/setup-node@')) {
                continue;
            }

            self::assertStringContainsString("node-version-file: '.nvmrc'", $workflow, basename($path));
            self::assertStringNotContainsString('node-version:', $workflow, basename($path));
        }

        $package = json_decode((string) file_get_contents($this->root . '/packages/admin/package.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('>=24 <25', $package['engines']['node'] ?? null);
        self::assertStringStartsWith('^24.', $package['devDependencies']['@types/node'] ?? '');
    }

    #[Test]
    public function browser_retries_are_visible_and_the_supported_matrix_is_explicit(): void
    {
        $config = (string) file_get_contents($this->root . '/packages/admin/playwright.config.ts');
        $workflow = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');
        $documentation = (string) file_get_contents($this->root . '/docs/testing/admin.md');

        self::assertStringContainsString('retries: process.env.CI ? 2 : 0', $config);
        self::assertStringContainsString("['json', { outputFile: 'test-results/results.json' }]", $config);
        self::assertStringContainsString("name: 'chromium'", $config);
        self::assertStringContainsString("name: 'firefox'", $config);
        self::assertStringNotContainsString("name: 'webkit'", $config);
        self::assertStringContainsString('Summarize Playwright retries', $workflow);
        self::assertStringContainsString('test-results/', $workflow);
        self::assertStringContainsString('first-attempt failures', $documentation);
    }

    #[Test]
    public function playwright_jobs_do_not_depend_on_live_os_package_mirrors(): void
    {
        $expectations = [
            'ci.yml' => 'npx playwright install chromium firefox',
            'admin.yml' => 'npx playwright install chromium',
            'release.yml' => 'npx playwright install chromium',
        ];

        foreach ($expectations as $filename => $browserInstall) {
            $workflow = (string) file_get_contents($this->root . '/.github/workflows/' . $filename);

            self::assertStringNotContainsString('playwright install --with-deps', $workflow, $filename);
            self::assertStringContainsString($browserInstall, $workflow, $filename);
            self::assertStringContainsString('path: ~/.cache/ms-playwright', $workflow, $filename);
            self::assertMatchesRegularExpression(
                '/name: Install Playwright browsers\R\s+timeout-minutes: 5\R\s+run: cd packages\/admin && npx playwright install/',
                $workflow,
                $filename,
            );
        }
    }

    #[Test]
    public function nuxt_developer_tools_are_never_enabled_in_production(): void
    {
        $config = (string) file_get_contents($this->root . '/packages/admin/nuxt.config.ts');
        $package = json_decode((string) file_get_contents($this->root . '/packages/admin/package.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue(version_compare($package['dependencies']['nuxt'] ?? '0', '4.5.2', '>='));
        self::assertStringContainsString("devtools: { enabled: process.env.NODE_ENV !== 'production' }", $config);
        self::assertStringNotContainsString('devtools: { enabled: true }', $config);
    }

    #[Test]
    public function admin_coverage_uses_the_honest_nuxt_45_baseline(): void
    {
        $config = (string) file_get_contents($this->root . '/packages/admin/vitest.config.ts');
        $documentation = (string) file_get_contents($this->root . '/docs/testing/admin.md');

        foreach (['lines: 66', 'statements: 64', 'functions: 64', 'branches: 62'] as $threshold) {
            self::assertStringContainsString($threshold, $config);
        }
        self::assertStringContainsString('grew from 3,214 to 3,772 statements', $documentation);
        self::assertStringContainsString('80 percent changed-statement ratchet', $documentation);
    }
}
