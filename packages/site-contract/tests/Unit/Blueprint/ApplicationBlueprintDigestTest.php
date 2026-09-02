<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Blueprint;

use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * Golden-digest and identity-formula proofs for `application_blueprint`
 * (#2785, design §5). The literals below are pinned from the current
 * normalization; re-running this test after any parser/normalization change
 * is the trip wire that proves the digest formula stayed stable.
 */
final class ApplicationBlueprintDigestTest extends TestCase
{
    private const string OLD_V1_MANIFEST_DIGEST = 'ff461fce0baea77dafbe5c57b582f73ba34e13ae26b2c1936737eeb93e753e1c';

    private const string MINIMAL_MANIFEST_DIGEST = '771b389dd66274bebf91c89dc83da011245944eb5f3a7a51ef9393b404bf3a0f';
    private const string MINIMAL_BLUEPRINT_DIGEST = 'c8f97ac7c32cb0740f865c1264e2ca58bc361e299c24cad2d6c8d6ce8a78f922';

    private const string COMPLETE_MANIFEST_DIGEST = '4bafce4382c9e6bf54be024e439fe2e584888f28a3ffb8f5185d91a0bc0bbafe';
    private const string COMPLETE_BLUEPRINT_DIGEST = 'f2f0c68ed4e9977c53b65216a15994ff7aaee06f4e8592e3f0c3334a32afd369';

    public function test_old_v1_fixture_digest_is_unchanged(): void
    {
        $yaml = $this->fixture('valid/old-v1-without-blueprint.yaml');
        $manifest = new SiteManifestParser()->parse($yaml);

        self::assertNull($manifest->applicationBlueprint);
        self::assertSame(self::OLD_V1_MANIFEST_DIGEST, $manifest->digest);
    }

    public function test_minimal_fixture_golden_digests(): void
    {
        $manifest = new SiteManifestParser()->parse($this->fixture('valid/minimal.yaml'));

        self::assertSame(self::MINIMAL_MANIFEST_DIGEST, $manifest->digest);
        self::assertNotNull($manifest->applicationBlueprint);
        self::assertSame(self::MINIMAL_BLUEPRINT_DIGEST, $manifest->applicationBlueprint->digest);

        // Re-parsing must reproduce the exact same pinned digests.
        $reparsed = new SiteManifestParser()->parse($this->fixture('valid/minimal.yaml'));
        self::assertSame(self::MINIMAL_MANIFEST_DIGEST, $reparsed->digest);
        self::assertSame(self::MINIMAL_BLUEPRINT_DIGEST, $reparsed->applicationBlueprint->digest);
    }

    public function test_complete_fixture_golden_digests(): void
    {
        $manifest = new SiteManifestParser()->parse($this->fixture('valid/complete.yaml'));

        self::assertSame(self::COMPLETE_MANIFEST_DIGEST, $manifest->digest);
        self::assertNotNull($manifest->applicationBlueprint);
        self::assertSame(self::COMPLETE_BLUEPRINT_DIGEST, $manifest->applicationBlueprint->digest);
    }

    public function test_changing_any_blueprint_value_changes_both_digests(): void
    {
        $baseline = new SiteManifestParser()->parse($this->fixture('valid/minimal.yaml'));

        $changed = str_replace('label: Article', 'label: Articles', $this->fixture('valid/minimal.yaml'));
        $manifest = new SiteManifestParser()->parse($changed);

        self::assertNotSame($baseline->digest, $manifest->digest, 'A blueprint value change must change the manifest digest.');
        self::assertNotSame($baseline->applicationBlueprint->digest, $manifest->applicationBlueprint->digest, 'A blueprint value change must change the blueprint digest.');
    }

    public function test_changing_application_name_changes_the_manifest_digest_but_not_the_blueprint_digest(): void
    {
        $baseline = new SiteManifestParser()->parse($this->fixture('valid/minimal.yaml'));

        $changed = str_replace(
            'name: Minimal Blueprint Application',
            'name: Renamed Blueprint Application',
            $this->fixture('valid/minimal.yaml'),
        );
        $manifest = new SiteManifestParser()->parse($changed);

        self::assertNotSame($baseline->digest, $manifest->digest, 'Changing context outside the section must change the manifest digest.');
        self::assertSame(
            $baseline->applicationBlueprint->digest,
            $manifest->applicationBlueprint->digest,
            'Changing context outside the section must not change the blueprint digest.',
        );
    }

    public function test_blueprint_section_round_trips_through_render_to_the_same_digest(): void
    {
        $parser = new SiteManifestParser();
        $manifest = $parser->parse($this->fixture('valid/complete.yaml'));
        $rendered = $parser->render($manifest);
        $reparsed = $parser->parse($rendered);

        self::assertSame($manifest->digest, $reparsed->digest);
        self::assertSame($manifest->applicationBlueprint->digest, $reparsed->applicationBlueprint->digest);
    }

    private function fixture(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Blueprint/' . $relative);
    }
}
