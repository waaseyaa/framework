<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the architectural boundary: the framework CORE stays runtime-agnostic.
 * The optional waaseyaa/frankenphp package (and its `dev` / `frankenphp:install`
 * commands) must NOT be pulled in by the curated consumer metapackages, and the
 * old FrankenPHP coupling in foundation must stay removed.
 */
#[CoversNothing]
final class CoreAgnosticTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * @return array<string,string> manifest path keyed by metapackage name
     */
    private static function manifest(string $package): array
    {
        $path = self::repoRoot() . '/packages/' . $package . '/composer.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function curatedMetapackages(): iterable
    {
        yield 'core' => ['core'];
        yield 'cms' => ['cms'];
        yield 'full' => ['full'];
    }

    #[Test]
    #[DataProvider('curatedMetapackages')]
    public function curated_metapackages_do_not_require_frankenphp(string $package): void
    {
        $manifest = self::manifest($package);
        $require = $manifest['require'] ?? [];
        $requireDev = $manifest['require-dev'] ?? [];

        self::assertArrayNotHasKey('waaseyaa/frankenphp', is_array($require) ? $require : [], sprintf(
            'waaseyaa/%s must stay runtime-agnostic — it must not require waaseyaa/frankenphp.',
            $package,
        ));
        self::assertArrayNotHasKey('waaseyaa/frankenphp', is_array($requireDev) ? $requireDev : []);
    }

    #[Test]
    public function foundation_no_longer_couples_to_frankenphp_binary_location(): void
    {
        // The binary locator moved out of core (foundation) into the optional
        // package; it must not creep back in.
        self::assertFileDoesNotExist(
            self::repoRoot() . '/packages/foundation/src/Runtime/FrankenPhpLocator.php',
            'FrankenPhpLocator must not live in core foundation (it belongs to waaseyaa/frankenphp).',
        );
    }

    #[Test]
    public function the_framework_meta_carries_frankenphp_so_the_skeleton_gets_it_by_default(): void
    {
        $root = json_decode((string) file_get_contents(self::repoRoot() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($root);
        $require = $root['require'] ?? [];
        self::assertIsArray($require);
        self::assertArrayHasKey(
            'waaseyaa/frankenphp',
            $require,
            'the whole-monorepo waaseyaa/framework meta should carry frankenphp so the skeleton serves with `composer run dev` by default.',
        );
    }
}
