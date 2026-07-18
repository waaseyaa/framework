<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Hydration;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;

/**
 * Read path for sql-column translatable entity types (WP05 / FR-028..FR-031).
 *
 * Issues a single LEFT JOIN against the primary table and its translation
 * sibling, ordered so the default-langcode row materialises first. Builds a
 * langcode → values map and hands it to the trait via
 * {@see TranslatableInterface::_setTranslationData()} so subsequent
 * `getTranslation($lc)` calls require no further round-trips (data-model
 * "single hydrate, many reads" invariant for WP10).
 *
 * @internal Owned by SqlEntityStorage; do not construct from application code.
 */
final class SqlColumnTranslationHydrator
{
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly EntityTypeInterface $entityType,
        private readonly EntityInstantiator $instantiator,
    ) {}

    /**
     * Load one entity and all its translations in a single round-trip.
     *
     * Returns `null` when no primary row exists for `$id`.
     *
     * Query shape (FR-028):
     *
     * ```sql
     * SELECT pri.<columns>, t.langcode AS _t_langcode, t.<translatable_columns>
     *   FROM <table> pri
     *   LEFT JOIN <table>__translation t ON t.entity_id = pri.<id>
     *   WHERE pri.<id> = ?
     *   ORDER BY CASE WHEN t.langcode = pri.default_langcode THEN 0 ELSE 1 END,
     *            t.langcode
     * ```
     *
     * The CASE expression guarantees the default-langcode row materialises
     * first so the hydrator can establish canonical entity values before
     * stamping the per-langcode overlay.
     */
    public function load(int|string $id): ?EntityInterface
    {
        $tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $langcodeKey = $keys['langcode'] ?? 'langcode';

        $translationHandler = new TranslationSchemaHandler($this->database);
        $translationTable = $translationHandler->translationTableName($tableName);

        $translatableFields = $translationHandler->partitionTranslatableFields($this->entityType);
        $translatableFieldNames = \array_keys($translatableFields);

        $quote = $this->identifierQuoter();

        $primaryColumns = $this->resolvePrimaryColumns($tableName, $idKey);

        // Build the SELECT list with quoted identifiers. Primary columns first,
        // then translation columns. The translation langcode is aliased to
        // `_t_langcode` so it never shadows the entity's own `langcode` field.
        $selectExprs = [];
        foreach ($primaryColumns as $col) {
            $selectExprs[] = 'pri.' . $quote($col) . ' AS ' . $quote('pri__' . $col);
        }
        $selectExprs[] = 't.' . $quote('langcode') . ' AS ' . $quote('_t_langcode');
        foreach ($translatableFieldNames as $col) {
            $selectExprs[] = 't.' . $quote($col) . ' AS ' . $quote('t__' . $col);
        }
        // Translation metadata (read-through; not field values).
        foreach (['translation_status', 'translation_source', 'translation_created', 'translation_changed'] as $meta) {
            $selectExprs[] = 't.' . $quote($meta) . ' AS ' . $quote('t__' . $meta);
        }

        $sql = \sprintf(
            'SELECT %s FROM %s pri LEFT JOIN %s t ON t.%s = pri.%s WHERE pri.%s = ? '
            . 'ORDER BY CASE WHEN t.%s = pri.%s THEN 0 ELSE 1 END, t.%s',
            \implode(', ', $selectExprs),
            $quote($tableName),
            $quote($translationTable),
            $quote('entity_id'),
            $quote($idKey),
            $quote($idKey),
            $quote('langcode'),
            $quote('default_langcode'),
            $quote('langcode'),
        );

        $result = $this->database->query($sql, [$id]);

        $primaryRow = [];
        $translationData = [];
        $defaultLangcode = 'en';
        $hasRows = false;
        $primaryHydrated = false;

        foreach ($result as $row) {
            $hasRows = true;
            $rowArr = (array) $row;

            if (!$primaryHydrated) {
                foreach ($primaryColumns as $col) {
                    $primaryRow[$col] = $rowArr['pri__' . $col] ?? null;
                }
                if (isset($primaryRow['default_langcode']) && $primaryRow['default_langcode'] !== '') {
                    $defaultLangcode = (string) $primaryRow['default_langcode'];
                } elseif (isset($primaryRow[$langcodeKey]) && $primaryRow[$langcodeKey] !== '') {
                    $defaultLangcode = (string) $primaryRow[$langcodeKey];
                }
                $primaryHydrated = true;
            }

            // Each joined row contributes one translation overlay. When the
            // entity has no translation rows yet (newly inserted before WP05
            // wired the write path) the LEFT JOIN yields a single row with
            // _t_langcode = null — we skip the overlay registration in that case.
            $tLangcode = $rowArr['_t_langcode'] ?? null;
            if ($tLangcode === null) {
                continue;
            }
            $lc = (string) $tLangcode;
            $overlay = [];
            foreach ($translatableFieldNames as $col) {
                if (\array_key_exists('t__' . $col, $rowArr)) {
                    $overlay[$col] = $rowArr['t__' . $col];
                }
            }
            $translationData[$lc] = $overlay;
        }

        if (!$hasRows || $primaryRow === []) {
            return null;
        }

        $defaultLc = $defaultLangcode;

        // If no translation rows were materialised yet, synthesise an overlay
        // for the default langcode so getTranslation($defaultLc) is still
        // well-formed.
        if ($translationData === []) {
            $defaultOverlay = [];
            foreach ($translatableFieldNames as $col) {
                if (\array_key_exists($col, $primaryRow)) {
                    $defaultOverlay[$col] = $primaryRow[$col];
                }
            }
            $translationData[$defaultLc] = $defaultOverlay;
        }

        // Merge the default-langcode overlay onto the primary row so canonical
        // entity values reflect the default translation.
        if (isset($translationData[$defaultLc])) {
            foreach ($translationData[$defaultLc] as $k => $v) {
                $primaryRow[$k] = $v;
            }
        }

        $entity = $this->instantiator->instantiate($this->entityType->getClass(), $primaryRow);

        if ($entity instanceof TranslatableInterface && \method_exists($entity, '_setTranslationData')) {
            $entity->_setTranslationData($translationData, $defaultLc);
        }
        if ($entity instanceof \Waaseyaa\Entity\EntityBase) {
            $known = array_keys($translationData);
            sort($known);
            $entity->_hydrateStructuralLanguages($defaultLc, $defaultLc, $known);
        }

        if (\method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        return $entity;
    }

    /**
     * Resolve the column list of the primary table (excluding `_data` since
     * sql-column doesn't carry a blob).
     *
     * @return list<string>
     */
    private function resolvePrimaryColumns(string $tableName, string $idKey): array
    {
        $columns = [];
        // Best-effort SELECT * row probe; we only need the keys.
        $probe = $this->database->select($tableName)
            ->fields($tableName)
            ->range(0, 1)
            ->execute();
        foreach ($probe as $row) {
            foreach ((array) $row as $col => $_) {
                if (\is_string($col) && $col !== '_data') {
                    $columns[] = $col;
                }
            }
            return $columns;
        }

        // Empty table fallback: rely on the entity-type's known keys + a
        // default_langcode column.
        $keys = $this->entityType->getKeys();
        $fallback = [$idKey];
        foreach (['uuid', 'bundle', 'label', 'langcode'] as $name) {
            if (isset($keys[$name])) {
                $fallback[] = $keys[$name];
            }
        }
        $fallback[] = 'default_langcode';
        return \array_values(\array_unique($fallback));
    }

    /**
     * Identifier quoter callback. Uses DBAL when available, otherwise a safe
     * double-quote fallback.
     *
     * @return \Closure(string): string
     */
    private function identifierQuoter(): \Closure
    {
        $db = $this->database;
        if ($db instanceof DBALDatabase) {
            return static fn(string $id): string => $db->quoteIdentifier($id);
        }
        return static fn(string $id): string => '"' . \str_replace('"', '""', $id) . '"';
    }
}
