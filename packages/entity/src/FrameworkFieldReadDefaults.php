<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Framework-owned classifications for physical fields not declared by an
 * application bundle definition.
 *
 * This is the single source for runtime layout compilation and activation
 * preflight. Consumers must classify only the fields they add.
 *
 * @api
 */
final class FrameworkFieldReadDefaults
{
    /** @var array<string, string> */
    private const array EXACT = [
        'classification_label_definition|*|display_name' => 'public',
        'group_type|*|label' => 'public',
        'group|*|name' => 'public',
        'media_type|*|label' => 'public',
        'menu|*|label' => 'public',
        'retention_policy|*|name' => 'protected',

        'relationship|*|confidence' => 'protected',
        'relationship|*|directionality' => 'protected',
        'relationship|*|end_date' => 'protected',
        'relationship|*|from_entity_id' => 'protected',
        'relationship|*|from_entity_type' => 'protected',
        'relationship|*|notes' => 'protected',
        'relationship|*|source_ref' => 'protected',
        'relationship|*|start_date' => 'protected',
        'relationship|*|status' => 'protected',
        'relationship|*|to_entity_id' => 'protected',
        'relationship|*|to_entity_type' => 'protected',
        'relationship|*|weight' => 'protected',

        'media_version|*|blob_uri' => 'internal',
        'media_version|*|created_at' => 'internal',
        'media_version|*|created_by' => 'internal',
        'media_version|*|label' => 'internal',
        'media_version|*|media_uuid' => 'internal',
        'media_version|*|mime' => 'internal',
        'media_version|*|sha256' => 'internal',
        'media_version|*|size' => 'internal',
        'media_version|*|vid' => 'internal',

        'user|*|password_hash' => 'internal',
        'user|*|role' => 'internal',
        'user|*|consent_date' => 'protected',
        'user|*|consent_on_file' => 'protected',
        'user|*|disabled' => 'protected',
        'user|*|must_reset_password' => 'protected',
    ];

    private const array UNIVERSAL_STRUCTURAL_FIELDS = [
        'bundle',
        'langcode',
        'default_langcode',
    ];

    private function __construct() {}

    /** @return array<string, FieldReadLevel> */
    public static function exactDefaults(): array
    {
        return array_map(
            static fn(string $level): FieldReadLevel => FieldReadLevel::from($level),
            self::EXACT,
        );
    }

    public static function resolve(string $entityType, string $bundle, string $field): ?FieldReadLevel
    {
        if (in_array($field, self::UNIVERSAL_STRUCTURAL_FIELDS, true)) {
            return FieldReadLevel::Public;
        }

        $level = self::EXACT[$entityType . '|' . $bundle . '|' . $field]
            ?? self::EXACT[$entityType . '|*|' . $field]
            ?? null;

        return $level !== null ? FieldReadLevel::from($level) : null;
    }

    public static function canonicalKey(string $entityType, string $bundle, string $field): ?string
    {
        if (in_array($field, self::UNIVERSAL_STRUCTURAL_FIELDS, true)) {
            return $entityType . '|*|' . $field;
        }
        $bundleKey = $entityType . '|' . $bundle . '|' . $field;
        if (isset(self::EXACT[$bundleKey])) {
            return $bundleKey;
        }
        $coreKey = $entityType . '|*|' . $field;

        return isset(self::EXACT[$coreKey]) ? $coreKey : null;
    }
}
