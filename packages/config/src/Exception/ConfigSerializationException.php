<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Exception;

/**
 * Raised when sync-store YAML files fail shape validation during
 * (de)serialisation.
 *
 * Examples:
 *  - Filename `<entity_type>.<entity_id>.yml` does not match `_meta.entity_type`.
 *  - Required `_meta` keys (`entity_type`, `uuid`, `langcode`) are absent.
 *  - Top-level keys outside the declared `FieldDefinition` set.
 *  - YAML value cannot be coerced to the declared field type
 *    (e.g. YAML int where field expects `datetime`).
 *
 * Stability scope (charter §5.5): the exception FQCN and the
 * `filename↔_meta.entity_type` failure mode are on stable surface for
 * `waaseyaa/config` v1.x. Named factories may be added; existing ones
 * keep their signatures.
 *
 * @api
 */
final class ConfigSerializationException extends \RuntimeException
{
    public static function entityTypeMismatch(string $filename, string $metaEntityType, string $derivedEntityType): self
    {
        return new self(sprintf(
            'Sync file "%s" has _meta.entity_type "%s" but filename implies "%s"; filename and meta must agree.',
            $filename,
            $metaEntityType,
            $derivedEntityType,
        ));
    }

    public static function missingMetaKey(string $filename, string $key): self
    {
        return new self(sprintf(
            'Sync file "%s" is missing required _meta key "%s".',
            $filename,
            $key,
        ));
    }

    public static function invalidFilename(string $filename): self
    {
        return new self(sprintf(
            'Sync filename "%s" does not match `<entity_type>.<entity_id>.yml` where each segment is `^[a-z][a-z0-9_]*$`.',
            $filename,
        ));
    }

    public static function strayField(string $filename, string $field): self
    {
        return new self(sprintf(
            'Sync file "%s" declares field "%s" that is not present in the entity type\'s FieldDefinition set.',
            $filename,
            $field,
        ));
    }

    public static function typeMismatch(string $field, string $expectedType, string $actualPhpType): self
    {
        return new self(sprintf(
            'Field "%s" expects YAML value mappable to "%s" but received PHP %s.',
            $field,
            $expectedType,
            $actualPhpType,
        ));
    }

    public static function malformedYaml(string $filename, string $reason): self
    {
        return new self(sprintf(
            'Sync file "%s" is not valid YAML: %s',
            $filename,
            $reason,
        ));
    }

    public static function missingMetaBlock(string $filename): self
    {
        return new self(sprintf(
            'Sync file "%s" is missing the required `_meta` block.',
            $filename,
        ));
    }

    public static function invalidMeta(string $filename, string $reason): self
    {
        return new self(sprintf('Sync file "%s" has invalid _meta: %s', $filename, $reason));
    }
}
