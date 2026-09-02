<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintStorage;

/**
 * Proves `Waaseyaa\SiteContract\Blueprint\BlueprintFieldType` and
 * `BlueprintStorage` (#2785, `packages/site-contract`, Layer 0) stay a subset
 * of the live field-type/storage-backend authorities (`packages/field`,
 * `packages/entity`, both Layer 1) WITHOUT `site-contract` importing either —
 * the same mirror-without-import pattern `PrimaryStorageBackend` itself
 * documents (`AttributeSelectedColumnBackendTest` proves the analogous mirror
 * against `ReservedBackendIds`). This test lives at the root because it reads
 * from both layers, which neither package may do about the other.
 */
#[CoversNothing]
final class ApplicationBlueprintVocabularyTest extends TestCase
{
    #[Test]
    public function blueprintFieldTypesAreASubsetOfTheLiveFieldTypeRegistryExcludingReferenceAndMedia(): void
    {
        $root = dirname(__DIR__, 2);
        $discovered = [];
        foreach (glob($root . '/packages/field/src/Item/*.php') as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match_all('/#\[FieldType\(\s*id:\s*\'([a-z_]+)\'/', $contents, $matches) > 0) {
                foreach ($matches[1] as $id) {
                    $discovered[$id] = true;
                }
            }
        }

        self::assertNotEmpty($discovered, 'Expected to discover at least one #[FieldType(id: ...)] attribute.');
        self::assertArrayHasKey('entity_reference', $discovered, 'entity_reference must exist in the live registry to prove the exclusion is deliberate.');
        self::assertArrayHasKey('file', $discovered);
        self::assertArrayHasKey('image', $discovered);

        $blueprintValues = array_map(static fn(BlueprintFieldType $case): string => $case->value, BlueprintFieldType::cases());

        foreach ($blueprintValues as $value) {
            self::assertArrayHasKey($value, $discovered, "BlueprintFieldType::{$value} must exist in the live field-type registry.");
        }

        self::assertNotContains('entity_reference', $blueprintValues, 'entity_reference is owned by relationships, not scalar fields.');
        self::assertNotContains('file', $blueprintValues, 'Media field types are out of the initial blueprint scope.');
        self::assertNotContains('image', $blueprintValues, 'Media field types are out of the initial blueprint scope.');

        $expectedExcluded = ['entity_reference', 'file', 'image'];
        $expectedIncluded = array_values(array_diff(array_keys($discovered), $expectedExcluded));
        sort($expectedIncluded, SORT_STRING);
        $actual = $blueprintValues;
        sort($actual, SORT_STRING);
        self::assertSame($expectedIncluded, $actual);
    }

    #[Test]
    public function blueprintStorageValuesEqualThePrimaryStorageBackendConstants(): void
    {
        self::assertSame(PrimaryStorageBackend::SQL_BLOB, BlueprintStorage::SqlBlob->value);
        self::assertSame(PrimaryStorageBackend::SQL_COLUMN, BlueprintStorage::SqlColumn->value);
        self::assertSame(
            PrimaryStorageBackend::ALL,
            array_map(static fn(BlueprintStorage $case): string => $case->value, BlueprintStorage::cases()),
        );
    }
}
