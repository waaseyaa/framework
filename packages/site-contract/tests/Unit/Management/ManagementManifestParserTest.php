<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Management;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\Management\ManagementManifestSchema;
use Waaseyaa\SiteContract\Tests\Fixtures\Management\ManagementFixture as F;

final class ManagementManifestParserTest extends TestCase
{
    public function testSchemaResourceMatchesCanonicalDefinition(): void
    {
        self::assertSame(ManagementManifestSchema::canonicalJson(), trim(file_get_contents(dirname(__DIR__, 3) . '/resources/management.schema.json')));
    }
    public function testCanonicalIdentityPreservesSchemaObjectsAndIgnoresOperationOrder(): void
    {
        $doc = F::document();
        $a = F::manifest($doc);
        $doc['operations'] = array_reverse($doc['operations']);
        $b = F::manifest($doc);
        self::assertSame($a->digest, $b->digest);
        self::assertStringContainsString('"properties":{}', $a->canonicalJson);
        self::assertSame($a->digest, new ManagementManifestParser()->parse($a->canonicalJson)->digest);
        self::assertSame('unsupported', $a->operations['forms.delete']['state']);
        self::assertSame('unsupported', $a->operations['forms.createDraft']['contract']->metadata['dry_run']);
    }

    #[DataProvider('invalidDocuments')]
    public function testRefusesMalformedOrMisleadingDeclarations(array $doc): void
    {
        $this->expectException(SiteManifestValidationException::class);
        F::manifest($doc);
    }

    public static function invalidDocuments(): iterable
    {
        $doc = F::document(); $doc['version'] = 2; yield 'future version' => [$doc];
        $doc = F::document(); $doc['unknown'] = true; yield 'unknown key' => [$doc];
        $doc = F::document(); $doc['operations'][] = $doc['operations'][0]; yield 'duplicate operation' => [$doc];
        $doc = F::document(); $doc['operations'][1]['contract'] = []; yield 'unsupported binding' => [$doc];
        $doc = F::document(); $doc['operations'][1]['reason'] = ''; yield 'empty reason' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['id'] = 'another-operation'; yield 'shadowed identity' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['required_scopes'] = []; yield 'no scopes' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['effects'] = ['grant-superuser']; yield 'unknown effects' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['verification'] = []; yield 'no acceptance' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['dry_run'] = true; yield 'ambiguous dry run' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['input_schema'] = []; yield 'untyped inputs' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['output_schema']['$schema'] = 'draft-07'; yield 'wrong schema dialect' => [$doc];
        $doc = F::document(); $doc['operations'][0]['contract']['required_scopes'] = ['forms:write', 'forms:write']; yield 'duplicate scope' => [$doc];
    }

    public function testDuplicateJsonKeysAreRefused(): void
    {
        $json = json_encode(F::document(), JSON_THROW_ON_ERROR);
        $json = str_replace('"version":1', '"version":1,"version":1', $json);
        $this->expectException(SiteManifestValidationException::class);
        new ManagementManifestParser()->parse($json);
    }

    public function testInvalidJsonAndOversizeInputAreRefused(): void
    {
        foreach (['{', str_repeat(' ', 1048577)] as $json) {
            try {
                new ManagementManifestParser()->parse($json);
                self::fail('Invalid input accepted.');
            } catch (SiteManifestValidationException $e) {
                self::assertSame('SITE030_INVALID_MANAGEMENT', $e->violations[0]->code);
            }
        }
    }
}
