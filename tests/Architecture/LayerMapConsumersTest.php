<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LayerMapConsumersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function package_layer_gate_exports_the_complete_canonical_map(): void
    {
        exec(
            'cd ' . escapeshellarg($this->root) . ' && php bin/check-package-layers --layer-map=json 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
        $map = json_decode(implode("\n", $output), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($map);

        $expected = [];
        foreach (glob($this->root . '/packages/*/composer.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            if (($data['type'] ?? null) === 'metapackage') {
                continue;
            }
            $name = (string) ($data['name'] ?? '');
            if (str_starts_with($name, 'waaseyaa/')) {
                $expected[] = substr($name, strlen('waaseyaa/'));
            }
        }
        sort($expected);
        $actual = array_keys($map);
        sort($actual);

        self::assertSame($expected, $actual);
    }

    #[Test]
    public function secondary_layer_consumers_do_not_maintain_private_package_maps(): void
    {
        $audit = (string) file_get_contents($this->root . '/bin/audit-require-dev-layers');
        self::assertStringContainsString('bin/check-package-layers', $audit);
        self::assertStringContainsString('--layer-map=json', $audit);
        self::assertStringNotContainsString('layer_by_short = {', $audit);

        foreach (glob($this->root . '/packages/*/composer.json') ?: [] as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey(
                'layer',
                $data['extra']['waaseyaa'] ?? [],
                str_replace($this->root . '/', '', $manifest) . ' duplicates the canonical layer map',
            );
        }
    }

    #[Test]
    public function phpstan_and_spec_drift_use_roster_wide_coverage(): void
    {
        $phpstan = (string) file_get_contents($this->root . '/phpstan.neon');
        self::assertMatchesRegularExpression('/^\s*- packages\s*$/m', $phpstan);
        self::assertDoesNotMatchRegularExpression('/^\s*- packages\/[^\/]+\/src\s*$/m', $phpstan);

        $drift = (string) file_get_contents($this->root . '/tools/drift-detector.sh');
        foreach (['attachment', 'bimaaji', 'cli', 'genealogy', 'listing', 'media', 'messaging', 'migration', 'search', 'ssr', 'workspace'] as $package) {
            self::assertStringContainsString('["packages/' . $package . '/"]=', $drift);
        }
    }

    #[Test]
    public function tracked_project_hooks_are_the_only_documented_hook_path(): void
    {
        self::assertFileDoesNotExist($this->root . '/scripts/install-git-hooks.sh');
        self::assertFileDoesNotExist($this->root . '/tools/git-hooks/pre-push');

        self::assertFileDoesNotExist($this->root . '/lefthook.yml');
        self::assertFileExists($this->root . '/bin/project-hooks');

        $composer = json_decode((string) file_get_contents($this->root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('bash bin/project-hooks install', $composer['scripts']['hooks:install'] ?? null);
        self::assertSame('bash bin/project-hooks doctor', $composer['scripts']['hooks:doctor'] ?? null);
    }
}
