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
