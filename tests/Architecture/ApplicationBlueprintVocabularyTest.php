<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;
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
    public function blueprintFieldTypesEqualTheLiveRegistryAdmissionAuthority(): void
    {
        $blueprintValues = array_map(static fn(BlueprintFieldType $case): string => $case->value, BlueprintFieldType::cases());
        sort($blueprintValues, SORT_STRING);

        $authority = new FieldSchemaAuthority(new FieldTypeManager());
        self::assertSame($authority->blueprintFieldTypeIds(), $blueprintValues);
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
