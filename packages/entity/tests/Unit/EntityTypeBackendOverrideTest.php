<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;

/** @covers \Waaseyaa\Entity\EntityType */
final class EntityTypeBackendOverrideTest extends TestCase
{
    public function testBackendOverrideKeepsCanonicalMetadataAndHasIsolatedCacheIdentity(): void
    {
        $default = EntityType::fromClass(BackendOverrideEntity::class);
        $column = EntityType::fromClass(BackendOverrideEntity::class, primaryStorageBackend: 'sql-column');
        self::assertSame('sql-blob', $default->getPrimaryStorageBackend());
        self::assertSame('sql-column', $column->getPrimaryStorageBackend());
        self::assertSame($column, EntityType::fromClass(BackendOverrideEntity::class, primaryStorageBackend: 'sql-column'));
        self::assertSame($default, EntityType::fromClass(BackendOverrideEntity::class));
        self::assertNotSame($column, $default);
        self::assertSame($default->getFieldDefinitions(), $column->getFieldDefinitions());
        self::assertSame($default->getKeys(), $column->getKeys());
        self::assertSame('fixture.guard', $column->getRequiredMutationInvariant());
        self::assertFalse($column->isApiExposed());
    }
}

#[ContentEntityType(id: 'backend_override_fixture', label: 'Fixture', api: false, storageBackend: 'sql-blob', requiredMutationInvariant: 'fixture.guard')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title')]
final class BackendOverrideEntity extends ContentEntityBase
{
    #[Field(label: 'Title')]
    public string $title = '';
}
