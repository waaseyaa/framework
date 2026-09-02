<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Seed;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Seed\SiteSeedParser;

/**
 * `waaseyaa.site-seed` v1 is the second authored input `site:init` accepts
 * (#2442): the identity/content-type half of a site, from which a preset
 * resolves the remaining product decisions. It is versioned and closed for
 * the same reason `waaseyaa.site` is — an unknown, duplicated, or ill-typed
 * key is an operator decision the framework would otherwise discard while
 * reporting success.
 */
final class SiteSeedParserTest extends TestCase
{
    public function test_a_complete_seed_is_typed_in_document_order(): void
    {
        $seed = new SiteSeedParser()->parse($this->validSeed(), 'seed.yaml');

        self::assertSame('example', $seed->application->id);
        self::assertSame('Example', $seed->application->name);
        self::assertSame('APP_ORIGIN', $seed->application->canonicalOriginConfigKey);
        self::assertSame(['page', 'announcement'], array_keys($seed->contentTypes));
        self::assertSame('/{slug}', $seed->contentTypes['page']->canonicalRoute);
        self::assertSame('/announcements/{slug}', $seed->contentTypes['announcement']->canonicalRoute);
    }

    #[DataProvider('rejectedSeedProvider')]
    public function test_rejected_seeds_fail_closed_at_their_original_pointer(
        string $yaml,
        string $expectedCode,
        string $expectedPath,
    ): void {
        try {
            new SiteSeedParser()->parse($yaml, 'seed.yaml');
            self::fail('Expected preset seed validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($expectedCode, $exception->violations[0]->code);
            self::assertSame($expectedPath, $exception->violations[0]->path);
            self::assertSame('seed.yaml', $exception->source);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedSeedProvider(): iterable
    {
        $test = new self('test_rejected_seeds_fail_closed_at_their_original_pointer');
        $valid = $test->validSeed();

        yield 'unknown top-level key' => [
            $valid . "\ngoverned_authoring: true\n",
            'SITE001_UNKNOWN_KEY',
            '/governed_authoring',
        ];
        yield 'unknown nested application key' => [
            str_replace("  name: Example\n", "  name: Example\n  tagline: Discarded\n", $valid),
            'SITE001_UNKNOWN_KEY',
            '/application/tagline',
        ];
        yield 'unknown nested canonical_origin key' => [
            str_replace(
                "    config_key: APP_ORIGIN\n",
                "    config_key: APP_ORIGIN\n    origin: https://example.test\n",
                $valid,
            ),
            'SITE001_UNKNOWN_KEY',
            '/application/canonical_origin/origin',
        ];
        yield 'unknown nested content type key' => [
            str_replace(
                "  - id: page\n",
                "  - id: page\n    label: Page\n",
                $valid,
            ),
            'SITE001_UNKNOWN_KEY',
            '/content_types/0/label',
        ];
        yield 'duplicate mapping key' => [
            str_replace("  name: Example\n", "  name: Example\n  name: Shadowed\n", $valid),
            'SITE000_INVALID_YAML',
            '/',
        ];
        yield 'duplicate content type identity' => [
            $valid . "  - id: page\n    canonical_route: /pages/{slug}\n",
            'SITE020_DUPLICATE_ID',
            '/content_types/2/id',
        ];
        yield 'duplicate canonical route' => [
            $valid . "  - id: post\n    canonical_route: /{slug}\n",
            'SITE022_DUPLICATE_ROUTE',
            '/content_types/2/canonical_route',
        ];
        yield 'wrong type for a mapping' => [
            str_replace(
                "application:\n  id: example\n  name: Example\n  canonical_origin:\n    config_key: APP_ORIGIN\n",
                "application: example\n",
                $valid,
            ),
            'SITE010_INVALID_TYPE',
            '/application',
        ];
        yield 'wrong type for a scalar' => [
            str_replace('  name: Example', '  name: 42', $valid),
            'SITE010_INVALID_TYPE',
            '/application/name',
        ];
        yield 'wrong type for a list' => [
            str_replace(
                "content_types:\n  - id: page\n    canonical_route: /{slug}\n  - id: announcement\n    canonical_route: /announcements/{slug}\n",
                "content_types: page\n",
                $valid,
            ),
            'SITE010_INVALID_TYPE',
            '/content_types',
        ];
        yield 'wrong type for the schema version' => [
            str_replace('version: 1', "version: '1'", $valid),
            'SITE010_INVALID_TYPE',
            '/version',
        ];
        yield 'missing schema key' => [
            str_replace("schema: waaseyaa.site-seed\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/schema',
        ];
        yield 'missing version key' => [
            str_replace("version: 1\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/version',
        ];
        yield 'missing application key' => [
            str_replace(
                "application:\n  id: example\n  name: Example\n  canonical_origin:\n    config_key: APP_ORIGIN\n",
                '',
                $valid,
            ),
            'SITE011_REQUIRED_KEY',
            '/application',
        ];
        yield 'missing content_types key' => [
            str_replace(
                "content_types:\n  - id: page\n    canonical_route: /{slug}\n  - id: announcement\n    canonical_route: /announcements/{slug}\n",
                '',
                $valid,
            ),
            'SITE011_REQUIRED_KEY',
            '/content_types',
        ];
        yield 'empty content_types list' => [
            str_replace(
                "content_types:\n  - id: page\n    canonical_route: /{slug}\n  - id: announcement\n    canonical_route: /announcements/{slug}\n",
                "content_types: []\n",
                $valid,
            ),
            'SITE012_EMPTY_VALUE',
            '/content_types',
        ];
        yield 'foreign schema identity' => [
            str_replace('schema: waaseyaa.site-seed', 'schema: waaseyaa.site', $valid),
            'SITE014_INVALID_VALUE',
            '/schema',
        ];
        yield 'unsupported future version' => [
            str_replace('version: 1', 'version: 2', $valid),
            'SITE003_UNSUPPORTED_SCHEMA_VERSION',
            '/version',
        ];
        yield 'unsupported non-positive version' => [
            str_replace('version: 1', 'version: 0', $valid),
            'SITE003_UNSUPPORTED_SCHEMA_VERSION',
            '/version',
        ];
        yield 'origin literal instead of a configuration key' => [
            str_replace('config_key: APP_ORIGIN', 'config_key: https://example.test', $valid),
            'SITE014_INVALID_VALUE',
            '/application/canonical_origin/config_key',
        ];
        yield 'unstable application identity' => [
            str_replace('id: example', 'id: Example', $valid),
            'SITE014_INVALID_VALUE',
            '/application/id',
        ];
        yield 'route that is not a local path' => [
            str_replace('canonical_route: /{slug}', 'canonical_route: https://example.test/{slug}', $valid),
            'SITE014_INVALID_VALUE',
            '/content_types/0/canonical_route',
        ];
        yield 'root that is not a mapping' => ["- page\n", 'SITE010_INVALID_TYPE', '/'];
        yield 'unparseable yaml' => ["application:\n\t- broken\n", 'SITE000_INVALID_YAML', '/'];
    }

    private function validSeed(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site-seed
            version: 1
            application:
              id: example
              name: Example
              canonical_origin:
                config_key: APP_ORIGIN
            content_types:
              - id: page
                canonical_route: /{slug}
              - id: announcement
                canonical_route: /announcements/{slug}

            YAML;
    }
}
