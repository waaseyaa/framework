<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps every `Waaseyaa\Seo\Discovery` contract governed, in BOTH directions.
 *
 * The parity gate discovers interfaces, abstract classes, traits, and enums
 * automatically, so it cannot see a concrete final class. Three of this
 * namespace's public types are concrete finals: {@see \Waaseyaa\Seo\Discovery\SitemapPath}
 * (a contributor's return type), {@see \Waaseyaa\Seo\Discovery\Exception\DiscoveryConfigurationException}
 * (the documented failure type), and {@see \Waaseyaa\Seo\Discovery\NonPublicEntityTypes}
 * (the floor an application relies on when it deletes its own blacklist). Being
 * invisible to the scanner is a property of the scanner, not a statement about
 * stability, so this test supplies the governance the gate cannot.
 *
 * The two directions, and what each one catches:
 *
 *  - **map entry removed, type kept.** The parity gate passes, because a
 *    concrete final that nothing references is simply not scanned. Only
 *    {@see self::every_api_discovery_type_is_governed_as_public_surface()}
 *    catches it, which is why that test is derived from the FILESYSTEM rather
 *    than from a hardcoded list: a type added to this namespace with `@api` and
 *    no map entry fails immediately, so a future contract cannot ship
 *    ungoverned the way one already nearly did.
 *  - **type removed, map entry kept.** `tools/check-surface-parity.php` fails
 *    this, for every one of this namespace's seven public types, and requires an
 *    explicit `### Removed` authorization to proceed (#2505 / #2510). An earlier
 *    revision of this class claimed the gate was disarmed for six of the seven by
 *    a `CHANGELOG.md` name match; that was true of the gate as it stood, and
 *    #2510 has since closed it. {@see self::every_governed_entry_still_resolves()}
 *    is kept as a cheap local assertion of the same property, so this suite still
 *    fails fast without shelling out to the gate, but the gate is the authority.
 *
 * `DiscoveryPath` is the negative control. It is `@internal` implementation
 * detail, so it must stay OUT of the map, and this test fails if it drifts in.
 */
#[CoversNothing]
final class DiscoverySurfaceGovernanceTest extends TestCase
{
    private const string NAMESPACE_PREFIX = 'Waaseyaa\\Seo\\Discovery\\';

    /** @return array<string, string> FQCN => disposition */
    private static function surfaceMap(): array
    {
        // Compose from the package-local declaration plane; the generated
        // docs/public-surface-map.php may lag until the next release cut.
        $root = dirname(__DIR__, 5);
        $composer = $root . '/tools/lib/compose-public-surface.php';
        if (!is_file($composer)) {
            self::markTestSkipped('Public surface declarations are only composable in the monorepo tree.');
        }

        /** @var array<string, string> $map */
        $map = require $composer;

        return $map;
    }

    /**
     * Every PHP type declared under `packages/seo/src/Discovery`, paired with the
     * file that declares it, discovered from disk rather than listed by hand.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function discoveryTypes(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src/Discovery';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $found = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
                continue;
            }
            if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $type) !== 1) {
                continue;
            }
            $found[$type[1]] = [trim($ns[1]) . '\\' . $type[1], $file->getPathname()];
        }

        ksort($found);
        self::assertNotSame([], $found, 'Discovery namespace must declare types.');

        foreach ($found as $short => $pair) {
            yield $short => $pair;
        }
    }

    #[Test]
    #[DataProvider('discoveryTypes')]
    public function every_api_discovery_type_is_governed_as_public_surface(string $fqcn, string $file): void
    {
        $source = (string) file_get_contents($file);
        $isApi = str_contains($source, '@api');
        $isInternal = str_contains($source, '@internal');
        $map = self::surfaceMap();

        self::assertNotSame(
            $isApi,
            $isInternal,
            $fqcn . ' must be exactly one of @api or @internal.',
        );

        if ($isInternal) {
            self::assertArrayNotHasKey(
                $fqcn,
                $map,
                $fqcn . ' is @internal and must not claim a public disposition.',
            );

            return;
        }

        self::assertArrayHasKey(
            $fqcn,
            $map,
            $fqcn . ' is @api but carries no disposition in docs/public-surface-map.php. '
            . 'Add it, or mark the type @internal.',
        );
        self::assertSame('public', $map[$fqcn], $fqcn . ' must be dispositioned public.');
    }

    /**
     * The precondition that keeps removal-without-deprecation detectable: the
     * parity gate refuses a map key that no longer loads, so every governed
     * Discovery FQCN must resolve today.
     */
    #[Test]
    public function every_governed_entry_still_resolves(): void
    {
        $governed = array_filter(
            array_keys(self::surfaceMap()),
            static fn(string $fqcn): bool => str_starts_with($fqcn, self::NAMESPACE_PREFIX),
        );

        self::assertNotSame([], $governed, 'The Discovery namespace must contribute governed surface.');

        foreach ($governed as $fqcn) {
            self::assertTrue(
                class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn),
                $fqcn . ' is listed in the public surface map but does not load.',
            );
        }
    }

    /**
     * The three concrete finals are the ones the parity scanner cannot see, so
     * name them explicitly as well. If one is renamed or dropped without the map
     * following, this fails even if the auto-discovery above is weakened.
     */
    #[Test]
    public function the_concrete_final_contracts_are_named_and_governed(): void
    {
        $map = self::surfaceMap();

        foreach ([
            \Waaseyaa\Seo\Discovery\SitemapPath::class,
            \Waaseyaa\Seo\Discovery\NonPublicEntityTypes::class,
            \Waaseyaa\Seo\Discovery\Exception\DiscoveryConfigurationException::class,
        ] as $fqcn) {
            self::assertArrayHasKey($fqcn, $map, $fqcn . ' must stay governed.');
            self::assertSame('public', $map[$fqcn]);
            self::assertTrue(class_exists($fqcn), $fqcn . ' must load.');
        }
    }

    #[Test]
    public function the_internal_path_validator_is_not_public_surface(): void
    {
        self::assertArrayNotHasKey(
            \Waaseyaa\Seo\Discovery\DiscoveryPath::class,
            self::surfaceMap(),
            'DiscoveryPath is an @internal implementation detail.',
        );
    }
}
