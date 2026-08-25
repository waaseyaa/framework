<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Fixtures;

use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * Bundled counterpart to {@see TestArticleEntity}: the entity type carries a
 * `type` bundle column, which is the production shape a `ContentTypeDescriptor`
 * with a non-null `bundle` targets (the framework node shape).
 *
 * Exists so the publisher's read-access fence is exercised on the bundled
 * composition too — `bundleCriteria()` contributes a `type` condition to the
 * access-checked listing query, which the bundle-less fixture never reaches.
 */
final class TestBundledArticleEntity extends ContentEntityBase
{
    private const DEFAULT_KEYS = [
        'id' => 'id',
        'uuid' => 'uuid',
        'bundle' => 'type',
        'label' => 'title',
        'revision' => 'revision_id',
    ];

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $slug = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(required: false, read: FieldReadLevel::Public)]
    public ?string $summary = null;

    #[Field(type: 'integer', required: false, read: FieldReadLevel::Protected)]
    public int $status = 0;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_bundled_article',
        array $entityKeys = self::DEFAULT_KEYS,
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'test_bundled_article',
            $entityKeys !== [] ? $entityKeys : self::DEFAULT_KEYS,
            $fieldDefinitions,
        );
    }

    public function getEntityTypeId(): string
    {
        return 'test_bundled_article';
    }
}
