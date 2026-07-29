<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Revisionable article-shaped fixture whose content fields are Public while
 * status is Protected, matching the production node publication boundary.
 */
final class TestArticleEntity extends ContentEntityBase
{
    private const DEFAULT_KEYS = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'title',
        'revision' => 'revision_id',
    ];

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $summary = null;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $body_html = null;

    #[Field(type: 'boolean', required: false, read: FieldReadLevel::Public)]
    public bool $promote = false;

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Protected)]
    public int $status = 0;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_article',
        array $entityKeys = self::DEFAULT_KEYS,
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'test_article',
            $entityKeys !== [] ? $entityKeys : self::DEFAULT_KEYS,
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'test_article';
    }
}
