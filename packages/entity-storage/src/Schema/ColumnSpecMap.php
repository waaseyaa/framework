<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Schema;

/**
 * Canonical map from a FieldDefinition type string to the Waaseyaa abstract
 * DBAL column type understood by DBALSchema::createTable()/mapFieldType().
 *
 * Single source of truth for the field-type → column-type mapping that was
 * previously duplicated in RevisionTableBuilder::columnSpecForType(),
 * SqlColumnSchemaBuilder::buildColumnSpec(), and
 * TranslationSchemaHandler::deriveValueColumnSpec(). Those call sites assemble
 * their own full column spec (not null / default / length) around this type, but
 * they all derive the abstract type from here so the mapping can never re-drift.
 */
final class ColumnSpecMap
{
    /**
     * Map a FieldDefinition type string (case-insensitive) to the abstract DBAL
     * column type. Unknown types fall back to 'text' (widest safety).
     */
    public static function abstractTypeFor(string $fieldType): string
    {
        return match (strtolower($fieldType)) {
            'string'             => 'varchar',
            'text'               => 'text',
            'int', 'integer'     => 'int',
            'bigint'             => 'int',
            'bool', 'boolean'    => 'boolean',
            'datetime'           => 'text',
            'json'               => 'text',
            'uuid'               => 'varchar',
            'float'              => 'float',
            'decimal', 'numeric' => 'text',
            default              => 'text',
        };
    }
}
