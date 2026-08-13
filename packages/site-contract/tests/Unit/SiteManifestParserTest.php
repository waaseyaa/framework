<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Capability\CapabilityState;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\SiteContract\SiteManifestSchema;
use Waaseyaa\SiteContract\Version\ManifestVersionDisposition;
use Waaseyaa\SiteContract\Version\SiteManifestVersionPolicy;

final class SiteManifestParserTest extends TestCase
{
    public function test_complete_manifest_is_typed_and_deterministic(): void
    {
        $manifest = new SiteManifestParser()->parse($this->validManifest());

        self::assertSame(1, $manifest->schemaVersion);
        self::assertSame(1, $manifest->generatorVersion);
        self::assertSame('sheguiandah-first-nation', $manifest->application->id);
        self::assertSame('APP_ORIGIN', $manifest->application->canonicalOriginConfigKey);
        self::assertSame('exact-lock', $manifest->framework->revisionPolicy);
        self::assertSame(str_repeat('a', 64), $manifest->framework->observedLockSha256);
        self::assertSame(['announcement', 'community_event', 'job_posting', 'page', 'post'], array_keys($manifest->contentTypes));
        self::assertSame(CapabilityState::Active, $manifest->capabilities['governed_authoring']->state);
        self::assertSame(CapabilityState::Planned, $manifest->capabilities['oidc']->state);
        self::assertSame(CapabilityState::NotNeeded, $manifest->capabilities['payments']->state);
        self::assertSame(['subscriber'], array_keys($manifest->personalDataStores));
        self::assertSame(['governed_authoring'], array_keys($manifest->recipes));
        self::assertSame('bin/maintenance/site-verify', $manifest->verificationCommand);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest->digest);

        $reordered = str_replace(
            "  name: Sheguiandah First Nation\n  id: sheguiandah-first-nation",
            "  id: sheguiandah-first-nation\n  name: Sheguiandah First Nation",
            $this->validManifest(),
        );
        self::assertSame($manifest->digest, new SiteManifestParser()->parse($reordered)->digest);
        self::assertSame($manifest->canonicalJson, new SiteManifestParser()->parse($reordered)->canonicalJson);
    }

    public function test_schema_resource_is_the_exact_runtime_schema(): void
    {
        $resource = dirname(__DIR__, 2) . '/resources/site.schema.json';

        self::assertFileExists($resource);
        self::assertSame(
            SiteManifestSchema::canonicalJson(),
            trim((string) file_get_contents($resource)),
        );
    }

    public function test_active_capability_may_explicitly_have_no_public_routes(): void
    {
        $manifest = new SiteManifestParser()->parse(str_replace(
            "    public_routes:\n      - /page-builder-preview/{id}",
            '    public_routes: []',
            $this->validManifest(),
        ));

        self::assertSame([], $manifest->capabilities['governed_authoring']->publicRoutes);
    }

    public function test_version_policy_never_migrates_or_downgrades_implicitly(): void
    {
        $policy = new SiteManifestVersionPolicy();

        self::assertSame(ManifestVersionDisposition::MigrationRequired, $policy->disposition(0));
        self::assertSame(ManifestVersionDisposition::Current, $policy->disposition(1));
        self::assertSame(ManifestVersionDisposition::UnsupportedFuture, $policy->disposition(2));
    }

    #[DataProvider('invalidManifestProvider')]
    public function test_invalid_manifests_fail_with_stable_codes(string $yaml, string $expectedCode, string $expectedPath): void
    {
        try {
            new SiteManifestParser()->parse($yaml, '.waaseyaa/site.yaml');
            self::fail('Expected manifest validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($expectedCode, $exception->violations[0]->code);
            self::assertSame($expectedPath, $exception->violations[0]->path);
            self::assertSame('.waaseyaa/site.yaml', $exception->source);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidManifestProvider(): iterable
    {
        $test = new self('test_invalid_manifests_fail_with_stable_codes');
        $valid = $test->validManifest();

        yield 'invalid yaml and duplicate mapping key' => [
            str_replace("schema: waaseyaa.site\n", "schema: waaseyaa.site\nschema: duplicate\n", $valid),
            'SITE000_INVALID_YAML',
            '/',
        ];
        yield 'unknown top-level key' => [$valid . "\nunknown: true\n", 'SITE001_UNKNOWN_KEY', '/unknown'];
        yield 'unknown nested key' => [
            str_replace('    config_key: APP_ORIGIN', "    config_key: APP_ORIGIN\n    literal: https://example.test", $valid),
            'SITE001_UNKNOWN_KEY',
            '/application/canonical_origin/literal',
        ];
        yield 'older schema requires explicit migration' => [
            str_replace('version: 1', 'version: 0', $valid),
            'SITE002_MIGRATION_REQUIRED',
            '/version',
        ];
        yield 'future schema is refused' => [
            str_replace('version: 1', 'version: 2', $valid),
            'SITE003_UNSUPPORTED_SCHEMA_VERSION',
            '/version',
        ];
        yield 'duplicate capability identity' => [
            str_replace(
                "  - id: payments\n    state: not_needed\n    reason: Payments are outside this application.",
                "  - id: governed_authoring\n    state: not_needed\n    reason: Duplicate for retained-red proof.",
                $valid,
            ),
            'SITE020_DUPLICATE_ID',
            '/capabilities/2/id',
        ];
        yield 'active capability missing required verification' => [
            str_replace("    verification:\n      - tests/Acceptance/GovernedAuthoringTest.php\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/capabilities/0/verification',
        ];
        yield 'active capability cannot claim empty lifecycle coverage' => [
            str_replace("    lifecycle:\n      - create\n      - revise\n      - publish\n      - archive", '    lifecycle: []', $valid),
            'SITE012_EMPTY_VALUE',
            '/capabilities/0/lifecycle',
        ];
        yield 'active capability rejects state-inappropriate fields' => [
            str_replace('    note: Activate only after key-custody readiness.', "    note: Activate only after key-custody readiness.\n    provider: forbidden", $valid),
            'SITE001_UNKNOWN_KEY',
            '/capabilities/1/provider',
        ];
        yield 'planned capability missing note' => [
            str_replace("    note: Activate only after key-custody readiness.\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/capabilities/1/note',
        ];
        yield 'excluded capability missing reason' => [
            str_replace("    reason: Payments are outside this application.\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/capabilities/2/reason',
        ];
        yield 'recipe points to non-active capability' => [
            str_replace('capability: governed_authoring', 'capability: oidc', $valid),
            'SITE031_RECIPE_CAPABILITY_NOT_ACTIVE',
            '/recipes/0/capability',
        ];
        yield 'personal data store missing deletion operation' => [
            str_replace("    deletion_operation: subscriber:delete\n", '', $valid),
            'SITE011_REQUIRED_KEY',
            '/personal_data_stores/0/deletion_operation',
        ];
        yield 'literal canonical origin is prohibited' => [
            str_replace("  canonical_origin:\n    config_key: APP_ORIGIN", "  canonical_origin:\n    config_key: https://example.test", $valid),
            'SITE014_INVALID_VALUE',
            '/application/canonical_origin/config_key',
        ];
        yield 'protocol-relative canonical route is prohibited' => [
            str_replace('canonical_route: /{slug}', 'canonical_route: //example.test/{slug}', $valid),
            'SITE014_INVALID_VALUE',
            '/content_types/0/canonical_route',
        ];
        yield 'public route cannot contain a query' => [
            str_replace('/page-builder-preview/{id}', '/page-builder-preview/{id}?draft=1', $valid),
            'SITE014_INVALID_VALUE',
            '/capabilities/0/public_routes/0',
        ];
        yield 'backslash route cannot become protocol relative after normalization' => [
            str_replace('canonical_route: /{slug}', 'canonical_route: /\\evil.example', $valid),
            'SITE014_INVALID_VALUE',
            '/content_types/0/canonical_route',
        ];
        yield 'dot-segment canonical route is prohibited' => [
            str_replace('canonical_route: /{slug}', 'canonical_route: /../admin', $valid),
            'SITE014_INVALID_VALUE',
            '/content_types/0/canonical_route',
        ];
        yield 'whitespace in public route is prohibited' => [
            str_replace('/page-builder-preview/{id}', '/page builder/{id}', $valid),
            'SITE014_INVALID_VALUE',
            '/capabilities/0/public_routes/0',
        ];
        yield 'backslash in public route is prohibited' => [
            str_replace('/page-builder-preview/{id}', '/\\evil.example', $valid),
            'SITE014_INVALID_VALUE',
            '/capabilities/0/public_routes/0',
        ];
        yield 'relative traversal is not a Composer package identity' => [
            str_replace('package: waaseyaa/page-builder', 'package: ../..', $valid),
            'SITE014_INVALID_VALUE',
            '/capabilities/0/package',
        ];
        yield 'hidden relative vendor is not a Composer package identity' => [
            str_replace('package: waaseyaa/page-builder', 'package: .hidden/pkg', $valid),
            'SITE014_INVALID_VALUE',
            '/capabilities/0/package',
        ];
    }

    private function validManifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              name: Sheguiandah First Nation
              id: sheguiandah-first-nation
              canonical_origin:
                config_key: APP_ORIGIN
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - id: page
                canonical_route: /{slug}
              - id: post
                canonical_route: /news/{slug}
              - id: community_event
                canonical_route: /events/{slug}
              - id: job_posting
                canonical_route: /employment/{slug}
              - id: announcement
                canonical_route: /announcements/{slug}
            capabilities:
              - id: governed_authoring
                state: active
                package: waaseyaa/page-builder
                provider: site.page_builder
                configuration_authority: .waaseyaa/site.yaml#/capabilities/governed_authoring
                public_routes:
                  - /page-builder-preview/{id}
                data_classification: public
                lifecycle:
                  - create
                  - revise
                  - publish
                  - archive
                verification:
                  - tests/Acceptance/GovernedAuthoringTest.php
              - id: oidc
                state: planned
                note: Activate only after key-custody readiness.
              - id: payments
                state: not_needed
                reason: Payments are outside this application.
            personal_data_stores:
              - id: subscriber
                classification: personal
                consent_operation: subscriber:consent
                retention: P2Y
                export_operation: subscriber:export
                deletion_operation: subscriber:delete
            recipes:
              - id: governed_authoring
                version: 1
                capability: governed_authoring
                artifact_digest: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }
}
