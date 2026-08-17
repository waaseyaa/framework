<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RandomOrderScopeSelectorTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        require_once $this->repoRoot . '/bin/lib/random-order-scope.php';
    }

    #[Test]
    public function it_loads_the_committed_manifest(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        self::assertContains('phpunit.xml.dist', $manifest['protected']);
        self::assertContains('composer.lock', $manifest['protected']);
        self::assertContains('bin/select-random-order-scope', $manifest['protected']);
        self::assertArrayHasKey('docs/', $manifest['prefixes']);
        self::assertSame(['cli'], $manifest['prefixes']['bin/waaseyaa']);
    }

    #[Test]
    public function it_rejects_a_prefix_that_shadows_another(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [
            ['path' => 'tools/', 'rationale' => 'repo tooling'],
            ['path' => 'tools/phpstan/', 'rationale' => 'shadowed'],
        ]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/ambiguous manifest prefix/');
        ros_load_manifest($root);
    }

    #[Test]
    public function it_rejects_an_entry_without_a_rationale(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [['path' => 'docs/']]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/rationale/');
        ros_load_manifest($root);
    }

    #[Test]
    public function it_rejects_a_seed_naming_an_absent_package(): void
    {
        $root = $this->fixtureRoot(['prefixes' => [
            ['path' => 'docs/', 'rationale' => 'docs', 'seeds' => ['no-such-package']],
        ]]);

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/absent package/');
        ros_load_manifest($root);
    }

    #[Test]
    public function it_expands_rename_records_to_both_paths(): void
    {
        $raw = "R096\0packages/node/src/Old.php\0packages/media/src/New.php\0"
            . "M\0packages/api/src/Kept.php\0"
            . "D\0packages/user/src/Gone.php\0";

        self::assertSame([
            'packages/api/src/Kept.php',
            'packages/media/src/New.php',
            'packages/node/src/Old.php',
            'packages/user/src/Gone.php',
        ], ros_parse_name_status($raw));
    }

    #[Test]
    public function a_rename_across_packages_seeds_both_packages(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);
        $result = ros_classify(
            ['packages/node/src/Old.php', 'packages/media/src/New.php'],
            $manifest,
            $this->repoRoot,
        );

        self::assertNull($result['full_reason']);
        self::assertSame(['media', 'node'], $result['seeds']);
    }

    #[Test]
    public function selector_inputs_force_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        foreach ([
            'bin/select-random-order-scope',
            'bin/build-phpunit-shards',
            'bin/test-random-order',
            'tools/random-order-scope-manifest.json',
            'phpunit.xml.dist',
            'composer.json',
            'composer.lock',
            '.github/workflows/ci.yml',
            '.github/workflows/nightly.yml',
            'packages/api/composer.json',
        ] as $path) {
            $result = ros_classify([$path], $manifest, $this->repoRoot);
            self::assertNotNull($result['full_reason'], "{$path} must force the complete inventory.");
        }
    }

    #[Test]
    public function an_unknown_root_path_forces_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        foreach (['scripts/deploy.sh', '.gitignore', 'public/index.php', 'defaults/ingestion.yaml'] as $path) {
            $result = ros_classify([$path], $manifest, $this->repoRoot);
            self::assertSame("unclassified path: {$path}", $result['full_reason']);
        }
    }

    #[Test]
    public function a_package_without_a_parsable_manifest_forces_the_complete_inventory(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);
        $result = ros_classify(['packages/no-such-package/src/A.php'], $manifest, $this->repoRoot);

        self::assertSame(
            'package is absent from the dependency graph: no-such-package',
            $result['full_reason'],
        );
    }

    #[Test]
    public function bounded_prefixes_seed_only_what_they_declare(): void
    {
        $manifest = ros_load_manifest($this->repoRoot);

        self::assertSame([], ros_classify(['docs/specs/api-layer.md'], $manifest, $this->repoRoot)['seeds']);
        self::assertSame([], ros_classify(['bin/check-dead-code'], $manifest, $this->repoRoot)['seeds']);
        self::assertSame(['cli'], ros_classify(['bin/waaseyaa'], $manifest, $this->repoRoot)['seeds']);
    }

    /** @param array{prefixes: list<array<string, mixed>>} $document */
    private function fixtureRoot(array $document): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('ros', true);
        mkdir($root . '/tools', 0o777, true);
        mkdir($root . '/packages/cli', 0o777, true);
        file_put_contents(
            $root . '/packages/cli/composer.json',
            json_encode(['name' => 'waaseyaa/cli'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $root . '/tools/random-order-scope-manifest.json',
            json_encode(['schema_version' => 1, 'protected' => [], ...$document], JSON_THROW_ON_ERROR),
        );

        return $root;
    }
}
