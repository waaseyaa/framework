<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Listing\Exception\UnsupportedListingException;

/**
 * Boot-time validator for {@see ListingDefinition} entries in a
 * {@see ListingDefinitionRegistry}.
 *
 * Runs after entity-type registration but before route dispatch
 * (FR-052). Raises {@see UnsupportedListingException} on the first
 * failure encountered (FR-053 fail-fast — the kernel refuses to boot
 * with the exception's full message in the error log; no silent
 * "broken listing" state).
 *
 * Rule families (per spec §3.13 / FR-050..FR-053 and
 * {@code contracts/listing-definition.md}):
 *
 *  - **A** `pageSize > 1000` without {@code allowUnbounded()}
 *  - **B** `pageSize === null` without {@code allowUnbounded()}
 *  - **C** `approximateTotal === true` with {@code allowUnbounded()} (no useful semantics)
 *  - **D** Entity type must exist in the {@see EntityTypeManager}
 *  - **E** Bundle (if set) must be a registered bundle for the entity type
 *  - **F** Every filter/sort field must exist on the entity type
 *  - **G** Every filter/sort field's storage backend must support query
 *  - **H** Operator-to-field-type compatibility (BETWEEN/comparison/LIKE/IN)
 *  - **I** `langcode` filter only on translatable entity types
 *
 * Rule G operates at the definition level: a field stored in a `Column`
 * is queryable by definition; a field stored in the `_data` blob is not
 * (its values live inside an opaque JSON column and storage backends
 * such as `SqlBlobBackend` report {@code supportsQuery=false}). This is
 * the same invariant the registrar-owned field-storage gateway contract
 * tests exercise for live backends.
 *
 * Lexicographic ordering on string fields is permitted: per ADR-010 the
 * comparison operators (`LT`, `LTE`, `GT`, `GTE`) are valid on string
 * fields and yield lexicographic ordering. Rule H restricts only the
 * operators whose semantics make no sense on a given type
 * (e.g. {@code STARTS_WITH} on an integer field).
 *
 * @api
 */
final class ListingDefinitionValidator
{
    /**
     * Field types considered comparable for `BETWEEN` and the comparison
     * operators when combined with non-string scalar values. Strings are
     * comparable lexicographically and handled separately in Rule H.
     */
    private const COMPARABLE_TYPES = ['integer', 'int', 'float', 'decimal', 'datetime', 'date', 'timestamp'];

    public function __construct(private readonly EntityTypeManager $entityTypes) {}

    /**
     * Validate every registered definition. Fail-fast: throws on the
     * first failure encountered (FR-053).
     */
    public function validate(ListingDefinitionRegistry $registry): void
    {
        foreach ($registry->all() as $definition) {
            $this->validateDefinition($definition);
        }
    }

    private function validateDefinition(ListingDefinition $def): void
    {
        // Rule A: pageSize > 1000 without allowUnbounded()
        if ($def->pageSize !== null && $def->pageSize > 1000 && !$def->isUnbounded()) {
            throw new UnsupportedListingException(
                $def->id,
                null,
                'pageSize exceeds 1000 without allowUnbounded()',
            );
        }

        // Rule B: pageSize === null without allowUnbounded()
        if ($def->pageSize === null && !$def->isUnbounded()) {
            throw new UnsupportedListingException(
                $def->id,
                null,
                'pageSize is null without allowUnbounded()',
            );
        }

        // Rule C: approximateTotal=true with pageSize=null and allowUnbounded()
        if ($def->approximateTotal === true && $def->pageSize === null && $def->isUnbounded()) {
            throw new UnsupportedListingException(
                $def->id,
                null,
                'approximateTotal=true with allowUnbounded() has no useful semantics',
            );
        }

        // Rule D: entity type must exist
        if (!$this->entityTypes->hasDefinition($def->entityType)) {
            throw new UnsupportedListingException(
                $def->id,
                null,
                sprintf('entity type "%s" is not registered', $def->entityType),
            );
        }

        $entityType = $this->entityTypes->getDefinition($def->entityType);

        // Rule E: bundle (if set) must exist for the entity type
        if ($def->bundle !== null) {
            $this->requireBundleExists($def, $entityType);
        }

        $fieldsByName = $this->collectFields($entityType, $def->bundle);

        // Rule F: every filter/sort field must exist on the entity type
        // Rule G: every filter/sort field's storage must support query
        // Rule H: operator-to-field-type compatibility (per filter)
        // Rule I: langcode filter only on translatable entity types
        foreach ($def->filters as $filter) {
            $this->validateFilter($def, $filter, $entityType, $fieldsByName);
        }
        foreach ($def->sorts as $sort) {
            $this->validateSort($def, $sort, $fieldsByName);
        }
    }

    /**
     * @param array<string, FieldDefinitionInterface> $fieldsByName
     */
    private function validateFilter(
        ListingDefinition $def,
        FilterDefinition $filter,
        EntityTypeInterface $entityType,
        array $fieldsByName,
    ): void {
        // Rule I: langcode filter only on translatable entity types
        if ($filter->field === 'langcode' && !$entityType->isTranslatable()) {
            throw new UnsupportedListingException(
                $def->id,
                'langcode',
                sprintf('langcode filter on non-translatable entity type "%s"', $def->entityType),
            );
        }

        // Rule F: field exists?
        if (!isset($fieldsByName[$filter->field])) {
            throw new UnsupportedListingException(
                $def->id,
                $filter->field,
                'field not declared on the entity type',
            );
        }
        $fieldDef = $fieldsByName[$filter->field];

        // Rule G: backend supports query?
        $this->requireSupportsQuery($def, $fieldDef);

        // Rule H: operator-to-type compatibility
        $this->requireOperatorCompatibility($def, $filter, $fieldDef);
    }

    /**
     * @param array<string, FieldDefinitionInterface> $fieldsByName
     */
    private function validateSort(
        ListingDefinition $def,
        SortDefinition $sort,
        array $fieldsByName,
    ): void {
        if (!isset($fieldsByName[$sort->field])) {
            throw new UnsupportedListingException(
                $def->id,
                $sort->field,
                'field not declared on the entity type',
            );
        }
        $this->requireSupportsQuery($def, $fieldsByName[$sort->field]);
    }

    private function requireBundleExists(ListingDefinition $def, EntityTypeInterface $entityType): void
    {
        $bundles = $this->entityTypes->getFieldRegistry()->bundleNamesFor($entityType->id());

        if (!in_array($def->bundle, $bundles, true)) {
            throw new UnsupportedListingException(
                $def->id,
                null,
                sprintf(
                    'bundle "%s" is not registered for entity type "%s"',
                    $def->bundle ?? '',
                    $def->entityType,
                ),
            );
        }
    }

    /**
     * Collect all fields available on the entity type (core fields plus
     * bundle-scoped fields when a bundle is set). Returns a name-keyed
     * map for O(1) lookup.
     *
     * @return array<string, FieldDefinitionInterface>
     */
    private function collectFields(EntityTypeInterface $entityType, ?string $bundle): array
    {
        $registry = $this->entityTypes->getFieldRegistry();
        $fields = $registry->coreFieldsFor($entityType->id());

        if ($bundle !== null) {
            $fields = [...$fields, ...$registry->bundleFieldsFor($entityType->id(), $bundle)];
        }

        return $fields;
    }

    private function requireSupportsQuery(ListingDefinition $def, FieldDefinitionInterface $fieldDef): void
    {
        // Definition-level proxy for the storage-backend `supportsQuery()`
        // contract: `Column`-stored fields are queryable (live in their
        // own SQL columns); `Data`-stored fields are not (opaque JSON
        // blob in `_data`).
        if ($fieldDef->getStored() !== FieldStorage::Column) {
            throw new UnsupportedListingException(
                $def->id,
                $fieldDef->getName(),
                sprintf(
                    'field "%s" backend reports supportsQuery=false (stored in %s)',
                    $fieldDef->getName(),
                    $fieldDef->getStored()->value,
                ),
            );
        }
    }

    private function requireOperatorCompatibility(
        ListingDefinition $def,
        FilterDefinition $filter,
        FieldDefinitionInterface $fieldDef,
    ): void {
        $fieldType = strtolower($fieldDef->getType());
        $op = $filter->op;

        // String-only operators
        if (($op === Operator::STARTS_WITH || $op === Operator::CONTAINS) && $fieldType !== 'string' && $fieldType !== 'text') {
            throw new UnsupportedListingException(
                $def->id,
                $fieldDef->getName(),
                sprintf(
                    'operator %s requires a string/text field, got %s',
                    $op->value,
                    $fieldType,
                ),
            );
        }

        // BETWEEN requires a comparable type (numeric or temporal). Strings
        // are NOT comparable for BETWEEN per ADR-010 — BETWEEN is range
        // semantics and ambiguous on free-form text.
        if ($op === Operator::BETWEEN && !in_array($fieldType, self::COMPARABLE_TYPES, true)) {
            throw new UnsupportedListingException(
                $def->id,
                $fieldDef->getName(),
                sprintf(
                    'operator BETWEEN requires a numeric or temporal field, got %s',
                    $fieldType,
                ),
            );
        }

        // IN/NOT_IN: each element must be type-compatible with the field.
        if (($op === Operator::IN || $op === Operator::NOT_IN) && is_array($filter->value)) {
            foreach ($filter->value as $element) {
                if (!$this->valueMatchesFieldType($element, $fieldType)) {
                    throw new UnsupportedListingException(
                        $def->id,
                        $fieldDef->getName(),
                        sprintf(
                            'operator %s element type incompatible with field type %s',
                            $op->value,
                            $fieldType,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Loose value↔type match used by Rule H to validate IN/NOT_IN
     * element types. Permissive on numeric coercion (an `int` value is
     * acceptable on a `float` field) and accepts strings as a universal
     * representation for date/datetime fields (callers pass ISO-8601
     * strings in practice).
     */
    private function valueMatchesFieldType(mixed $value, string $fieldType): bool
    {
        return match ($fieldType) {
            'string', 'text', 'date', 'datetime', 'timestamp' => is_string($value),
            'integer', 'int' => is_int($value),
            'float', 'decimal' => is_int($value) || is_float($value),
            'boolean', 'bool' => is_bool($value),
            default => true, // unknown/custom types: do not block here
        };
    }
}
