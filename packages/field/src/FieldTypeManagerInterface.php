<?php

declare(strict_types=1);

namespace Waaseyaa\Field;

use Waaseyaa\Plugin\PluginManagerInterface;

/**
 * @api
 */
interface FieldTypeManagerInterface extends PluginManagerInterface
{
    public function getDefaultSettings(string $fieldType): array;

    /** @return array<string, array<string, mixed>> */
    public function getColumns(string $fieldType): array;

    /** @return array<string, mixed> */
    public function jsonSchemaFor(FieldDefinitionInterface $def): array;

    /** @return array<string, mixed> */
    public function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array;

    /** @return array<string, array<string, mixed>> */
    public function schemaFor(FieldDefinitionInterface $def): array;

    /** @return array<string, mixed> */
    public function entityStorageColumnSchemaFor(
        FieldDefinitionInterface $def,
        ?FieldStorageSchemaContext $context = null,
    ): array;

    /** @return list<string> */
    public function blueprintFieldTypeIds(): array;
}
