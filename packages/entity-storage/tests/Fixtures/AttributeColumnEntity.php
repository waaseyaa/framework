<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;
use Waaseyaa\Field\FieldStorage;

/**
 * An attribute-defined entity type that selects the sql-column backend and
 * declares indexed facets (#2157).
 *
 * Before #2157 this was not expressible: `EntityType::fromClass()` had no way
 * to select a backend, so every attribute-driven type fell back to sql-blob and
 * every field declared `FieldStorage::Column` silently landed in `_data` with
 * no index.
 *
 * @internal Test fixture.
 */
#[ContentEntityType(
    id: 'attribute_column_entity',
    label: 'Attribute column entity',
    storageBackend: PrimaryStorageBackend::SQL_COLUMN,
)]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class AttributeColumnEntity extends ContentEntityBase
{
    #[Field(label: 'Title', stored: FieldStorage::Column)]
    public string $title = '';

    /** A listing facet: physically materialised AND indexed. */
    #[Field(required: false, label: 'Source key', stored: FieldStorage::Column, indexed: true)]
    public string $source_key = '';

    /** A second, distinct indexed facet. */
    #[Field(required: false, type: 'integer', label: 'Last seen', stored: FieldStorage::Column, indexed: true)]
    public int $last_seen = 0;

    /** Column-backed but deliberately not indexed. */
    #[Field(required: false, label: 'Note', stored: FieldStorage::Column)]
    public string $note = '';

    /**
     * Declared Data-stored, and therefore non-indexable by API contract
     * (`#[Field]` rejects `indexed: true` here).
     *
     * It IS still materialised as a column under this fixture's sql-column
     * backend, which creates a column for every declared field regardless of
     * `stored:` — see
     * `on_the_column_backend_every_declared_field_is_materialised_and_there_is_no_blob()`,
     * which pins that pre-existing behaviour. Do not read `FieldStorage::Data`
     * as "never a column".
     */
    #[Field(required: false, type: 'text', label: 'Payload', stored: FieldStorage::Data)]
    public string $payload = '';
}
