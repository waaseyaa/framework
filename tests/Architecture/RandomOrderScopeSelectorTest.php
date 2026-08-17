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

    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        require_once $this->repoRoot . '/bin/lib/random-order-scope.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
        $this->fixtureRoots = [];
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

        $this->fixtureRoots[] = $root;

        return $root;
    }

    #[Test]
    public function closure_follows_consumers_transitively(): void
    {
        $graph = ros_package_graph($this->repoRoot);

        $leaf = ros_closure($graph['reverse'], ['genealogy']);
        self::assertSame(['genealogy'], $leaf);

        $hub = ros_closure($graph['reverse'], ['entity']);
        self::assertContains('entity', $hub);
        self::assertContains('node', $hub);
        self::assertContains('api', $hub);
        self::assertGreaterThan(50, count($hub), 'entity is a hub; its consumer closure is large.');
    }

    #[Test]
    public function closure_includes_require_dev_consumers(): void
    {
        $graph = ros_package_graph($this->repoRoot);
        $closure = ros_closure($graph['reverse'], ['testing']);

        self::assertContains('testing', $closure);
        self::assertGreaterThan(1, count($closure), 'packages/testing is consumed via require-dev.');
    }

    #[Test]
    public function closure_terminates_on_the_accepted_two_cycles(): void
    {
        $baseline = (string) file_get_contents($this->repoRoot . '/tools/package-layers-cycle-baseline.txt');
        self::assertStringContainsString('access', $baseline);

        $graph = ros_package_graph($this->repoRoot);
        $closure = ros_closure($graph['reverse'], ['access']);

        self::assertContains('access', $closure);
        self::assertContains('entity', $closure);
        self::assertSame($closure, array_values(array_unique($closure)));
    }

    #[Test]
    public function a_malformed_package_manifest_is_a_scope_failure(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('rosg', true);
        $this->fixtureRoots[] = $root;
        mkdir($root . '/packages/broken', 0o777, true);
        file_put_contents($root . '/packages/broken/composer.json', '{ not json');

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/packages\/broken\/composer\.json/');
        ros_package_graph($root);
    }

    #[Test]
    public function a_duplicate_package_name_is_a_scope_failure(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid('rosd', true);
        $this->fixtureRoots[] = $root;
        foreach (['one', 'two'] as $dir) {
            mkdir($root . '/packages/' . $dir, 0o777, true);
            file_put_contents(
                $root . '/packages/' . $dir . '/composer.json',
                json_encode(['name' => 'waaseyaa/same'], JSON_THROW_ON_ERROR),
            );
        }

        $this->expectException(\RosScopeFailure::class);
        $this->expectExceptionMessageMatches('/duplicate package name/');
        ros_package_graph($root);
    }

    #[Test]
    public function groups_follow_the_shard_planner(): void
    {
        self::assertSame('packages/api', ros_group_of('packages/api/tests/Unit/ATest.php'));
        self::assertSame('tests/Integration', ros_group_of('tests/Integration/PhaseN/BTest.php'));
        self::assertSame('tests/Architecture', ros_group_of('tests/Architecture/CTest.php'));
    }

    #[Test]
    public function unattributable_files_pin_their_group_into_the_always_run_set(): void
    {
        $document = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);

        self::assertContains('tests/Architecture', $document['always_run_groups']);
        self::assertContains('tests/Integration', $document['always_run_groups']);
    }

    #[Test]
    public function attribution_is_ambiguity_intolerant(): void
    {
        $graph = ros_package_graph($this->repoRoot);

        $attributed = ros_attribute(
            'tests/Integration/GraphQL/GraphQlSchemaValidationTest.php',
            $graph['psr4'],
            $this->repoRoot,
        );
        self::assertNotNull($attributed);
        self::assertNotSame([], $attributed);

        self::assertNull(
            ros_attribute('tests/Architecture/CiContractOrderingTest.php', $graph['psr4'], $this->repoRoot),
            'A repo-state contract test imports no package namespace and must be unattributable.',
        );
    }

    #[Test]
    public function a_selected_file_expands_to_its_complete_group(): void
    {
        $document = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);
        $inventory = ros_inventory($this->repoRoot);

        foreach ($document['selected_groups'] as $group) {
            $expected = array_values(array_filter(
                array_keys($inventory),
                static fn(string $path): bool => ros_group_of($path) === $group,
            ));
            foreach ($expected as $path) {
                self::assertContains(
                    $path,
                    $document['selected_paths'],
                    "Group {$group} was selected, so {$path} must be selected with it.",
                );
            }
        }
    }

    #[Test]
    public function the_document_records_its_inputs_and_is_deterministic(): void
    {
        $first = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);
        $second = ros_select($this->repoRoot, 'HEAD', 'HEAD', null);

        self::assertSame($first, $second);
        foreach (['manifest', 'composer_graph', 'phpunit_config', 'selector'] as $key) {
            self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $first['digests'][$key]);
        }
        self::assertSame(1, $first['schema_version']);
        self::assertGreaterThan(0, $first['inventory_files']);
    }

    #[Test]
    public function an_absent_base_selects_the_complete_inventory_without_erroring(): void
    {
        $result = $this->runSelector(['--base=']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
        self::assertNotNull($document['fallback_reason']);
        self::assertSame($document['inventory_files'], $document['selected_files']);
    }

    #[Test]
    public function an_unreachable_base_selects_the_complete_inventory(): void
    {
        $result = $this->runSelector(['--base=0000000000000000000000000000000000000000']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
    }

    #[Test]
    public function an_empty_diff_selects_the_complete_inventory(): void
    {
        $result = $this->runSelector(['--base=HEAD']);

        self::assertSame(0, $result['exit_code'], $result['output']);
        $document = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('full', $document['mode']);
        self::assertSame('the diff is empty', $document['fallback_reason']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, output: string}
     */
    private function runSelector(array $arguments): array
    {
        $command = [PHP_BINARY, $this->repoRoot . '/bin/select-random-order-scope', ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit_code' => proc_close($process), 'output' => $output];
    }
}
