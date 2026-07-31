<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\BackendRegistry\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the two entity types the #2160 regression boots a real kernel
 * against, through the ordinary `extra.waaseyaa.providers` discovery path.
 *
 * @internal Test fixture.
 */
final class BackendRegistryEntityProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([RegistryColumnEntity::class, RegistryBlobEntity::class] as $class) {
            $this->entityType(EntityType::fromClass($class));
        }
    }
}

/**
 * Attribute-defined, sql-column backed, with two indexed facets.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(
    id: 'registry_column_entity',
    label: 'Registry column entity',
    storageBackend: PrimaryStorageBackend::SQL_COLUMN,
)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class RegistryColumnEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    #[Field(required: false, label: 'Source key', stored: FieldStorage::Column, indexed: true)]
    public string $source_key = '';

    #[Field(required: false, type: 'integer', label: 'Last seen', stored: FieldStorage::Column, indexed: true)]
    public int $last_seen = 0;

    /** Column-backed but not indexed: proves indexes follow the declaration. */
    #[Field(required: false, label: 'Note', stored: FieldStorage::Column)]
    public string $note = '';
}

/**
 * The backward-compatibility control: declares nothing new, so it must resolve
 * to sql-blob and keep the historical `_data` shape exactly.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(id: 'registry_blob_entity', label: 'Registry blob entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class RegistryBlobEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    #[Field(required: false, label: 'Facet', stored: FieldStorage::Column)]
    public string $facet = '';
}
