<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\RevisionableEntityTrait;
use Waaseyaa\Entity\RevisionableInterface;

/**
 * Test entity class with revision support.
 */
#[ContentEntityType(id: 'test_revisionable')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'title', revision: 'revision_id')]
class TestRevisionableEntity extends ContentEntityBase implements RevisionableInterface
{
    use RevisionableEntityTrait;

    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $revision_log;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $tagline;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $summary;
    #[Field(type: 'boolean', read: FieldReadLevel::Public)] public bool $flag;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $published_revision_id;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $source_uri;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $entity_id;
    #[Field(type: 'boolean', read: FieldReadLevel::Public)] public bool $status;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $revision_created;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $revision_author;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $folder;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $field_a;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $field_b;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
