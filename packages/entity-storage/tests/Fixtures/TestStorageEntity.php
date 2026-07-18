<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Test entity class for storage tests.
 */
#[ContentEntityType(id: 'test_entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
class TestStorageEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $label;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $name;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $body;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $summary;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $description;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $mail;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $email;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $phone;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $website;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $role;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $category;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $tagline;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $revision_log;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $some_data_field;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $translation_status;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $translation_source;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $tenant_id;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $business;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $org_code;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $owner_id;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $priority;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $status;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $entity_id;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $flag;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $tags;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $metadata;

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
