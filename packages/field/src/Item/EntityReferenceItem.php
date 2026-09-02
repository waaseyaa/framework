<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Item;

use Waaseyaa\Field\AbstractFieldType;
use Waaseyaa\Field\Attribute\FieldType;
use Waaseyaa\Field\FieldDefinitionInterface;

#[FieldType(
    id: 'entity_reference',
    label: 'Entity Reference',
    description: 'A field containing a reference to another entity.',
    category: 'reference',
    defaultCardinality: 1,
)]
/**
 * @api
 */
final class EntityReferenceItem extends AbstractFieldType implements \Waaseyaa\Field\FieldValueKindProviderInterface
{
    /** @var list<string> */
    public const array TARGET_ENTITY_TYPE_SETTING_KEYS = [
        'target_entity_type_id', 'targetEntityTypeId', 'target_type',
    ];

    /**
     * Resolve a reference target using the canonical and compatibility aliases.
     * Empty aliases are skipped; malformed present values are rejected.
     * @throws \InvalidArgumentException
     */
    public static function targetEntityTypeIdFor(FieldDefinitionInterface $def): ?string
    {
        foreach (self::TARGET_ENTITY_TYPE_SETTING_KEYS as $key) {
            $value = $def->getSetting($key);
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Entity-reference field "%s" setting "%s" must be a non-empty string; got %s.',
                    $def->getName(),
                    $key,
                    get_debug_type($value),
                ));
            }
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    public static function valueKind(): \Waaseyaa\Field\FieldValueKind
    {
        return \Waaseyaa\Field\FieldValueKind::EntityReference;
    }

    public static function entityStorageColumnSchemaFor(
        \Waaseyaa\Field\FieldDefinitionInterface $def,
        ?\Waaseyaa\Field\FieldStorageSchemaContext $context = null,
    ): array {
        self::assertTargetEntityType($def);

        return ['type' => 'varchar', 'length' => (int) ($def->getSetting('length') ?? 255)];
    }

    public static function supportsBlueprint(): bool
    {
        return false;
    }

    public static function entityValueJsonSchemaFor(\Waaseyaa\Field\FieldDefinitionInterface $def): array
    {
        self::assertTargetEntityType($def);

        return ['type' => 'string'];
    }

    private static function assertTargetEntityType(FieldDefinitionInterface $def): void
    {
        if (self::targetEntityTypeIdFor($def) === null) {
            throw new \InvalidArgumentException(sprintf(
                'Entity-reference field "%s" requires target entity type metadata in one of: %s.',
                $def->getName(),
                implode(', ', self::TARGET_ENTITY_TYPE_SETTING_KEYS),
            ));
        }
    }

    public static function schema(): array
    {
        return [
            'target_id' => ['type' => 'int'],
            'target_type' => ['type' => 'varchar', 'length' => 255],
        ];
    }

    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_id' => ['type' => 'integer'],
                'target_type' => ['type' => 'string'],
            ],
        ];
    }
}
