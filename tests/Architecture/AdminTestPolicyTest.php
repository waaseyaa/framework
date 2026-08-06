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
}
