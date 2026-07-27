<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Integration\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Bundle-carrying test entity for the bundle-field query-planning suite.
 *
 * Entity type id `content_item`, bundle key `type`. The constructor mirrors
 * {@see ContentEntityBase::__construct()} verbatim so the storage hydration
 * pipeline instantiates this subclass correctly. Fields carry explicit
 * Public read levels so the fail-closed field-read layout releases them.
 */
final class BundleContentItemEntity extends ContentEntityBase
{
    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Public)]
    public ?int $status = null;

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $subtitle = null;

    private const DEFAULT_KEYS = [
        'id' => 'id',
        'uuid' => 'uuid',
        'bundle' => 'type',
        'label' => 'title',
        'langcode' => 'langcode',
    ];

    public function __construct(
        array $values = [],
        string $entityTypeId = 'content_item',
        array $entityKeys = self::DEFAULT_KEYS,
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'content_item',
            $entityKeys !== [] ? $entityKeys : self::DEFAULT_KEYS,
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'content_item';
    }
}
