<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Fixtures\AttributeFirstEntities;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * Simple content entity used by EntityType::fromClass() happy-path tests.
 */
#[ContentEntityType(id: 'simple', label: 'Simple', description: 'A simple test entity.', api: true)]
final class SimpleFixture extends ContentEntityBase
{
    #[Field(label: 'Title')]
    public string $title = '';

    #[Field]
    public ?int $count = null;

    #[Field(default: false)]
    public bool $active = false;
}

/**
 * Revisionable entity fixture — carries a revision key so that
 * EntityType::fromClass(RevisionableFixture::class, revisionable: true) passes T036.
 * Also carries langcode + default_langcode keys so that translatable: true passes T007 (WP02).
 */
#[ContentEntityType(id: 'revisionable_fixture', label: 'Revisionable Fixture')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', revision: 'vid', langcode: 'langcode', default_langcode: 'default_langcode')]
final class RevisionableFixture extends ContentEntityBase
{
    #[Field(label: 'Title')]
    public string $title = '';
}

/**
 * Entity with no #[Field] properties — empty field-map case.
 */
#[ContentEntityType(id: 'empty_fields', label: 'Empty Fields')]
final class EmptyFieldsFixture extends ContentEntityBase
{
}

/**
 * Entity with no label — fromClass() should default to ucfirst(typeId).
 */
#[ContentEntityType(id: 'no_label_fixture')]
final class NoLabelFixture extends ContentEntityBase
{
    #[Field]
    public string $value = '';
}

/**
 * Class extending ContentEntityBase WITHOUT a #[ContentEntityType] attribute.
 * Used to verify fromClass() throws EntityMetadataException.
 */
final class MissingAttributeFixture extends ContentEntityBase
{
    #[Field]
    public string $name = '';
}

/**
 * Parent class for the inheritance fixture pair.
 */
#[ContentEntityType(id: 'factory_parent', label: 'Factory Parent')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'name')]
class FactoryParentFixture extends ContentEntityBase
{
    #[Field(label: 'Parent name')]
    public string $name = '';

    #[Field]
    public ?int $weight = null;
}

/**
 * Child class overriding the `name` field and adding `extra`.
 */
#[ContentEntityType(id: 'factory_child', label: 'Factory Child')]
final class FactoryChildFixture extends FactoryParentFixture
{
    #[Field(label: 'Child name', description: 'Overridden in child.')]
    public string $name = '';

    #[Field]
    public string $extra = '';
}

/**
 * Wide entity with 12+ #[Field] properties for the fromClass() benchmark.
 */
#[ContentEntityType(id: 'benchmark_fixture', label: 'Benchmark Fixture')]
final class BenchmarkFixture extends ContentEntityBase
{
    #[Field(label: 'Title')]
    public string $title = '';

    #[Field]
    public string $body = '';

    #[Field]
    public ?int $count = null;

    #[Field(default: 0)]
    public int $score = 0;

    #[Field(default: false)]
    public bool $active = false;

    #[Field]
    public bool $featured = false;

    #[Field]
    public ?\DateTimeImmutable $createdAt = null;

    #[Field]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Field]
    public ?float $weight = null;

    #[Field]
    public string $slug = '';

    #[Field]
    public string $summary = '';

    #[Field(revisionable: true)]
    public string $author = '';

    #[Field(readOnly: true)]
    public string $hash = '';
}
