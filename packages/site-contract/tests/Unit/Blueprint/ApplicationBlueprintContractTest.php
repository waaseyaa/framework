<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Blueprint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * Packaged contract tests for the `application_blueprint` fixture corpus
 * (#2785, design §8). Every fixture in `tests/Fixtures/Blueprint/valid/`
 * must parse, round-trip through `render()` to the same canonical JSON and
 * digest, and parse identically when converted to CRLF. Every fixture in
 * `tests/Fixtures/Blueprint/invalid/` must fail with exactly the code and
 * JSON Pointer path recorded in its paired `.expect` file.
 */
final class ApplicationBlueprintContractTest extends TestCase
{
    #[DataProvider('validFixtureProvider')]
    public function test_valid_fixtures_parse_and_round_trip_deterministically(string $yaml, bool $expectBlueprint): void
    {
        $parser = new SiteManifestParser();
        $manifest = $parser->parse($yaml);

        if ($expectBlueprint) {
            self::assertNotNull($manifest->applicationBlueprint, 'Fixture is expected to carry an application_blueprint section.');
            self::assertSame(['site-application-blueprint-v1'], $manifest->requiredGeneratorFeatures);
        } else {
            self::assertNull($manifest->applicationBlueprint, 'Fixture is expected to carry no application_blueprint section.');
            self::assertSame([], $manifest->requiredGeneratorFeatures);
        }

        $rendered = $parser->render($manifest);
        $reparsed = $parser->parse($rendered);
        self::assertSame($manifest->canonicalJson, $reparsed->canonicalJson, 'render() -> parse() must round-trip to the same canonical JSON.');
        self::assertSame($manifest->digest, $reparsed->digest, 'render() -> parse() must round-trip to the same digest.');
        if ($expectBlueprint) {
            self::assertSame($manifest->applicationBlueprint->digest, $reparsed->applicationBlueprint->digest);
        }

        $crlf = str_replace("\n", "\r\n", $yaml);
        $crlfManifest = $parser->parse($crlf);
        self::assertSame($manifest->canonicalJson, $crlfManifest->canonicalJson, 'CRLF fixture bytes must parse identically to LF bytes.');
        self::assertSame($manifest->digest, $crlfManifest->digest);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function validFixtureProvider(): iterable
    {
        foreach (self::fixtureFiles('valid') as $path) {
            $expectBlueprint = basename($path) !== 'old-v1-without-blueprint.yaml';
            yield basename($path) => [(string) file_get_contents($path), $expectBlueprint];
        }
    }

    #[DataProvider('invalidFixtureProvider')]
    public function test_invalid_fixtures_fail_with_the_expected_code_and_path(string $yaml, string $expectedCode, string $expectedPath): void
    {
        try {
            new SiteManifestParser()->parse($yaml, 'fixture');
            self::fail('Expected manifest validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($expectedCode, $exception->violations[0]->code);
            self::assertSame($expectedPath, $exception->violations[0]->path);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidFixtureProvider(): iterable
    {
        foreach (self::fixtureFiles('invalid') as $path) {
            $expectPath = substr($path, 0, -strlen('.yaml')) . '.expect';
            self::assertFileExists($expectPath, "Missing .expect file for fixture {$path}.");
            $expectLine = trim((string) file_get_contents($expectPath));
            [$code, $pointer] = explode(' ', $expectLine, 2);
            yield basename($path) => [(string) file_get_contents($path), $code, $pointer];
        }
    }

    /** @return list<string> */
    private static function fixtureFiles(string $kind): array
    {
        $dir = dirname(__DIR__, 2) . '/Fixtures/Blueprint/' . $kind;
        $files = glob($dir . '/*.yaml');
        self::assertNotEmpty($files, "Expected at least one {$kind} fixture in {$dir}.");
        sort($files, SORT_STRING);

        return $files;
    }
}
