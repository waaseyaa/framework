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

    /** @return array<string, array{type: string, description?: string}> */
    public function getColumns(string $fieldType): array;

    /** @return array<string, mixed> */
    public function jsonSchemaFor(FieldDefinitionInterface $def): array;

    /** @return array<string, mixed> */
    public function entityValueJsonSchemaFor(FieldDefinitionInterface $def): array;

    /** @return array<string, array{type: string, description?: string}> */
    public function schemaFor(FieldDefinitionInterface $def): array;

    /** @return list<string> */
    public function blueprintFieldTypeIds(): array;
}
