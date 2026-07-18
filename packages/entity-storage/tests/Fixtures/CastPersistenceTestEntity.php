<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Content entity with $casts for repository/storage persistence tests (#1181).
 */
#[ContentEntityType(id: 'cast_persist_entity')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label', bundle: 'bundle', langcode: 'langcode')]
class CastPersistenceTestEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $label;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $score;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $tags;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public mixed $mode;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public mixed $nested_profile;

    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        'score' => 'int',
        'tags' => 'array',
        'mode' => CastPersistenceStringEnum::class,
        'nested_profile' => CastPersistenceOuterVo::class,
    ];

    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
