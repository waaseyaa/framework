<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Blueprint;

use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
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

    private const string COMPLETE_MANIFEST_DIGEST = '33d2f83b98fe1fcb94eac9491a287002a189a99057366a80e367d977224f839d';
    private const string COMPLETE_BLUEPRINT_DIGEST = 'ecb4ddbbb49f7a170265c3ce1cd29d00b0021b70ab62bb4dd7de7eb851a07bd4';

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

    public function test_authored_order_does_not_affect_canonical_digest(): void
    {
        $sorted = new SiteManifestParser()->parse($this->sortedAuthoringManifest());
        $reordered = new SiteManifestParser()->parse($this->reorderedAuthoringManifest());

        self::assertSame($sorted->canonicalJson, $reordered->canonicalJson, 'Reordering authored entities/fields/roles must not change canonical JSON.');
        self::assertSame($sorted->digest, $reordered->digest, 'Reordering authored entities/fields/roles must not change the manifest digest.');
        self::assertSame(
            $sorted->applicationBlueprint->digest,
            $reordered->applicationBlueprint->digest,
            'Reordering authored entities/fields/roles must not change the blueprint digest.',
        );
    }

    public function test_authored_order_is_preserved_for_validator_error_pointers(): void
    {
        // The "person" entity is authored FIRST but sorts second by id ("article" < "person").
        // A validator error about it must name its AUTHORED position (0), not its canonical
        // sorted position (1) -- this is the exact defect this test guards against.
        $yaml = $this->fixture('invalid/entity-order-preserves-authored-index.yaml');

        try {
            new SiteManifestParser()->parse($yaml, 'test');
            self::fail('Expected manifest validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame('SITE041_BLUEPRINT_UNKNOWN_CONTENT_TYPE', $exception->violations[0]->code);
            self::assertSame('/application_blueprint/entities/0/id', $exception->violations[0]->path);
        }
    }

    private function sortedAuthoringManifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Ordering Test Application
              id: ordering-test
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
            content_types:
              - id: alpha
                canonical_route: /alpha/{slug}
              - id: beta
                canonical_route: /beta/{slug}
            capabilities:
              - id: payments
                state: not_needed
                reason: Payments are outside this application.
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            application_blueprint:
              contract_version: 1
              entities:
                - id: alpha
                  label: Alpha
                  storage: sql-blob
                  revisionable: false
                  translatable: false
                  keys: { id: id, uuid: uuid, label: a_field }
                  fields:
                    - { id: a_field, type: string }
                    - { id: b_field, type: string }
                - id: beta
                  label: Beta
                  storage: sql-blob
                  revisionable: false
                  translatable: false
                  keys: { id: id, uuid: uuid, label: name }
                  fields:
                    - { id: name, type: string }
              relationships: []
              permissions:
                - { id: perm a, title: Permission A }
                - { id: perm b, title: Permission B }
              roles:
                - { id: role_a, label: Role A, permissions: [perm a, perm b] }
                - { id: role_b, label: Role B, permissions: [perm a] }
              policies: []
              workflows: []
              fixtures: []
              checks: []
            YAML;
    }

    private function reorderedAuthoringManifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Ordering Test Application
              id: ordering-test
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
            content_types:
              - id: alpha
                canonical_route: /alpha/{slug}
              - id: beta
                canonical_route: /beta/{slug}
            capabilities:
              - id: payments
                state: not_needed
                reason: Payments are outside this application.
            personal_data_stores: []
            recipes: []
            verification:
              command: bin/maintenance/site-verify
            application_blueprint:
              contract_version: 1
              entities:
                - id: beta
                  label: Beta
                  storage: sql-blob
                  revisionable: false
                  translatable: false
                  keys: { id: id, uuid: uuid, label: name }
                  fields:
                    - { id: name, type: string }
                - id: alpha
                  label: Alpha
                  storage: sql-blob
                  revisionable: false
                  translatable: false
                  keys: { id: id, uuid: uuid, label: a_field }
                  fields:
                    - { id: b_field, type: string }
                    - { id: a_field, type: string }
              relationships: []
              permissions:
                - { id: perm b, title: Permission B }
                - { id: perm a, title: Permission A }
              roles:
                - { id: role_b, label: Role B, permissions: [perm a] }
                - { id: role_a, label: Role A, permissions: [perm b, perm a] }
              policies: []
              workflows: []
              fixtures: []
              checks: []
            YAML;
    }

    private function fixture(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Blueprint/' . $relative);
    }
}
