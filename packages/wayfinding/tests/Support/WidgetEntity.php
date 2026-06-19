<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Support;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * A concrete content entity used only as a valid `class-string<EntityInterface>`
 * for the `class:` argument of EntityType in anchor tests. It is never
 * instantiated — the SchemaPresenter reads entity-type metadata, not the class.
 */
final class WidgetEntity extends ContentEntityBase
{
    /**
     * @param array<string, mixed>                   $values
     * @param array<string, string>                  $entityKeys
     * @param array<string, mixed>                   $fieldDefinitions
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
