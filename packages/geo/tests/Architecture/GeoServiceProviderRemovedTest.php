<?php

declare(strict_types=1);

namespace Waaseyaa\Geo\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #2752: `waaseyaa/geo` is a pure geospatial utility.
 * `GeoServiceProvider` was an unregistered, empty no-op — its only effect was
 * pulling in an otherwise-unneeded `waaseyaa/foundation` dependency and path
 * repository. Both were deleted; this test keeps them deleted.
 */
#[CoversNothing]
final class GeoServiceProviderRemovedTest extends TestCase
{
    private const COMPOSER_JSON = __DIR__ . '/../../composer.json';

    #[Test]
    public function geo_service_provider_class_no_longer_exists(): void
    {
        self::assertFalse(
            class_exists(\Waaseyaa\Geo\GeoServiceProvider::class),
            'GeoServiceProvider was deleted as an unreachable no-op (#2752); it must not be reintroduced.',
        );
    }

    #[Test]
    public function composer_json_does_not_require_foundation(): void
    {
        /** @var array{require?: array<string, string>} $composerJson */
        $composerJson = json_decode((string) file_get_contents(self::COMPOSER_JSON), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey(
            'waaseyaa/foundation',
            $composerJson['require'] ?? [],
            'waaseyaa/geo is a pure utility package and must not depend on waaseyaa/foundation (#2752).',
        );
    }

    #[Test]
    public function composer_json_declares_no_path_repositories(): void
    {
        /** @var array{repositories?: mixed} $composerJson */
        $composerJson = json_decode((string) file_get_contents(self::COMPOSER_JSON), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey(
            'repositories',
            $composerJson,
            'waaseyaa/geo has no internal waaseyaa/* dependency left, so it needs no path repositories (#2752).',
        );
    }
}
