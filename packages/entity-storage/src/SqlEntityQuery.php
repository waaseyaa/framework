<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\EntityStorage\Exception\BundleAmbiguousFieldException;
use Waaseyaa\EntityStorage\Exception\MissingQueryAccountException;
use Waaseyaa\EntityStorage\Exception\UnknownFieldException;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldStorage;

/**
 * SQL-based entity query implementation.
 *
 * Wraps the database select query builder to provide entity-level
 * querying with conditions, sorting, ranges, and counting.
 *
 * When a FieldDefinitionRegistry is provided, field references in conditions
 * and sorts are resolved per docs/specs/bundle-scoped-fields.md §Query: core
 * fields stay on the base table, bundle-scoped fields trigger an INNER JOIN
 * against the `{base}__{bundle}` subtable (deduplicated per bundle), and
 * field names that exist in multiple bundles must be narrowed by an explicit
 * bundle condition (or by a sibling bundle-scoped condition that uniquely
 * identifies the bundle).
 *
 * Access checking is enabled by default (see {@see accessCheck()}). When
 * enabled, `execute()` hydrates each candidate row, runs
 * `EntityAccessHandler::check($entity, 'view', $account)`, and keeps only rows
 * whose result is Allowed (deny-by-default — a Neutral "no policy opined"
 * result drops the row, matching the entity gate and every serializing
 * consumer; audit C-6). Callers MUST bind an account via {@see setAccount()}
 * before execution; otherwise {@see MissingQueryAccountException} is thrown
 * (fail-closed).
 */
final class SqlEntityQuery implements EntityQueryInterface
{
    private readonly string $tableName;
    private readonly string $idKey;
    private readonly ?string $bundleKey;

    /** @var array<int, array{field: string, value: mixed, operator: string}> */
    private array $conditions = [];

    /** @var array<int, array{field: string, direction: string}> */
    private array $sorts = [];

    private ?int $rangeOffset = null;
    private ?int $rangeLimit = null;
    private bool $isCount = false;

    /** @var array<string, bool> */
    private array $columnCache = [];

    /**
     * Memoized set of core field names whose registered storage hint is
     * FieldStorage::Data. Mirrors SqlEntityStorage::getDataStoredCoreFieldNames()
     * so the read path consults the same registry hint as the write path
     * (mission #1257 WP04, K2). Null = not yet computed.
     *
     * @var array<string, true>|null
     */
    private ?array $dataStoredCoreFieldNames = null;

    /**
     * Account bound to this query for per-row access checking. When
     * {@see $accessCheckEnabled} is true and this is null, {@see execute()}
     * throws {@see MissingQueryAccountException} (fail-closed per FR-005 / C-006).
     */
    private ?AccountInterface $account = null;

    /**
     * When true (the default), {@see execute()} runs an
     * `EntityAccessHandler::check($entity, 'view', $account)` per candidate row
     * and keeps only rows whose result is Allowed (deny-by-default; audit C-6).
     * When false, the candidate IDs are returned without hydration — a fast
     * bypass path reserved for system contexts (background jobs, index warmers)
     * per FR-004 / C-004.
     */
    private bool $accessCheckEnabled = true;

    /**
     * Lazy-resolved {@see EntityAccessHandler} consulted by {@see execute()}
     * when access checking is enabled. The query has no constructor-injected
     * DI container; the handler is injected via {@see withAccessHandler()}
     * (used by `SqlEntityStorage::getQuery()` wiring per #1714 / by test
     * harnesses), or — as a fail-closed fallback — lazy-instantiated as an
     * empty handler that returns Neutral for every entity. Under deny-by-default
     * (audit C-6) Neutral is not Allowed, so the empty fallback drops every row:
     * an access-checked query against an unwired/misconfigured handler returns
     * nothing rather than leaking the unfiltered candidate set. Production wires
     * the real composed handler at boot, so this fallback is reached only by
     * direct construction or a missing resolver.
     */
    private ?EntityAccessHandler $accessHandler = null;

    /**
     * Optional hydrator callable used by {@see execute()} to materialize
     * candidate rows into entity objects for the per-row access check. The
     * callable signature is `callable(array<int, int|string>): array<int|string, EntityInterface>`
     * where the input is the list of candidate IDs and the output is an
     * id-keyed map of hydrated entities. Injected by
     * {@see withEntityLoader()}; the natural production binding is
     * `$storage->loadMultiple(...)` (wired in WP03). When the loader is null
     * and access checking is enabled, the query returns the candidate IDs
     * unfiltered — the access handler cannot run without entities to inspect.
     * This null-loader path is the pre-WP03 transitional behaviour; once WP03
     * wires every consumer, the loader is always set when the handler is set.
     *
     * @var (callable(array<int, int|string>): array<int|string, EntityInterface>)|null
     */
    private $entityLoader = null;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
        private readonly ?SqlEntityQueryResultCache $resultCache = null,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
    ) {
        $this->tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $this->idKey = $keys['id'] ?? 'id';
        $this->bundleKey = $keys['bundle'] ?? null;
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => $value,
            'operator' => $operator,
        ];

        return $this;
    }

    public function exists(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NOT NULL',
        ];

        return $this;
    }

    public function notExists(string $field): static
    {
        $this->conditions[] = [
            'field' => $field,
            'value' => null,
            'operator' => 'IS NULL',
        ];

        return $this;
    }

    public function sort(string $field, string $direction = 'ASC'): static
    {
        $this->sorts[] = [
            'field' => $field,
            'direction' => strtoupper($direction),
        ];

        return $this;
    }

    /**
     * Set the SQL `LIMIT`/`OFFSET` for the candidate page.
     *
     * Cursor contract (FR-007): the page cursor advances by the **unfiltered
     * candidate window**. Paginated callers MUST advance by adding `$limit` to
     * the previous `$offset`, NOT by adding `count(execute())`. Example: a
     * 25-row page request may return 18 surviving rows after access
     * filtering; the next-page cursor is `offset + 25` (not `offset + 18`).
     * This guarantees successive page requests do not re-scan candidates
     * already evaluated for the same query.
     */
    public function range(int $offset, int $limit): static
    {
        $this->rangeOffset = $offset;
        $this->rangeLimit = $limit;

        return $this;
    }

    public function count(): static
    {
        $this->isCount = true;

        return $this;
    }

    public function accessCheck(bool $check = true): static
    {
        $this->accessCheckEnabled = $check;

        return $this;
    }

    public function setAccount(?AccountInterface $account): static
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Inject the {@see EntityAccessHandler} consulted by {@see execute()}.
     *
     * Package-internal wiring helper. The production binding is performed by
     * `SqlEntityStorage::getQuery()` (WP03); test harnesses inject directly.
     * Returning `$this` keeps the fluent interface consistent with the
     * existing chainable surface.
     *
     * @api
     */
    public function withAccessHandler(EntityAccessHandler $handler): static
    {
        $this->accessHandler = $handler;

        return $this;
    }

    /**
     * Inject the hydrator callable used to materialize candidate rows for
     * the per-row access check.
     *
     * Signature: `callable(array<int, int|string>): array<int|string, EntityInterface>`.
     * The input is the list of candidate IDs from the SQL window; the output
     * is an id-keyed map of hydrated entities (matching
     * `EntityStorageInterface::loadMultiple()`'s shape so the natural
     * production binding is `$storage->loadMultiple(...)`).
     *
     * @param callable(array<int, int|string>): array<int|string, EntityInterface> $loader
     *
     * @api
     */
    public function withEntityLoader(callable $loader): static
    {
        $this->entityLoader = $loader;

        return $this;
    }

    /**
     * Resolve a field name to its SQL form.
     *
     * Returns a {@see ResolvedField} that carries both the SQL text and enough
     * shape to route it through the right DBAL seam (WP6): an *identifier*
     * (bare column or qualified `table.field`, NOT pre-quoted — auto-quoted by
     * `condition()`/`orderBy()`), or an *expression* (a `json_extract(...)`
     * fragment emitted verbatim through `whereRaw()`/`orderByRaw()`).
     *
     * With a routing map (produced by {@see routeFields()}), an identifier is
     * qualified with the base or subtable name so that it is unambiguous across
     * the JOINed subtables. Without routing, the legacy unqualified form is
     * returned.
     *
     * @param array<string, ?string> $routing Map of field name to target
     *        bundle (null for core/base), as produced by routeFields().
     */
    private function resolveField(string $field, array $routing = []): ResolvedField
    {
        if (\array_key_exists($field, $routing)) {
            $bundle = $routing[$field];
            $targetTable = $bundle === null
                ? $this->tableName
                : SqlSchemaHandler::resolveSubtableName($this->tableName, $bundle, $this->entityType->id());
            $quotedAlias = $this->database->quoteIdentifier($targetTable);

            // K2 (mission #1257 WP04): registry hint wins over schema column
            // lookup. A core field with FieldStorage::Data is always read from
            // `_data` JSON, even when a legacy column lingers in the base
            // schema. This matches SqlEntityStorage::splitForStorage() on the
            // write side via getDataStoredCoreFieldNames(); both paths consult
            // the same FieldDefinition->getStored() hint, so reads cannot
            // shadow writes.
            if ($bundle === null && isset($this->getDataStoredCoreFieldNames()[$field])) {
                $this->assertQueryableJsonFieldName($field);

                return ResolvedField::expression(
                    'json_extract(' . $quotedAlias . '._data, \'$.' . $field . '\')',
                    isJsonExtract: true,
                );
            }

            $cacheKey = $targetTable . "\0" . $field;
            if (!isset($this->columnCache[$cacheKey])) {
                $this->columnCache[$cacheKey] = $this->database->schema()->fieldExists($targetTable, $field);
            }
            if ($this->columnCache[$cacheKey]) {
                // Qualified identifier — BARE `table.field`; condition()/orderBy()
                // auto-quote it to `"table"."field"` (WP6).
                return ResolvedField::identifier($targetTable . '.' . $field);
            }

            $this->assertQueryableJsonFieldName($field);

            return ResolvedField::expression(
                'json_extract(' . $quotedAlias . '._data, \'$.' . $field . '\')',
                isJsonExtract: true,
            );
        }

        if (!isset($this->columnCache[$field])) {
            $this->columnCache[$field] = $this->database->schema()->fieldExists($this->tableName, $field);
        }

        if ($this->columnCache[$field]) {
            // Plain column — BARE name; condition()/orderBy() auto-quote it (WP6).
            return ResolvedField::identifier($field);
        }

        $this->assertQueryableJsonFieldName($field);

        return ResolvedField::expression(
            "json_extract(_data, '\$." . $field . "')",
            isJsonExtract: true,
        );
    }

    /**
     * Guards the raw `json_extract(...)` interpolation sink against SQL
     * metacharacters in a field name. Thin wrapper over the shared
     * {@see \Waaseyaa\EntityStorage\Query\JsonFieldName::assertQueryable()} so
     * this sink and {@see \Waaseyaa\EntityStorage\Driver\SqlStorageDriver}'s
     * twin sink use one identical implementation (audit R2 WP1).
     *
     * @throws \InvalidArgumentException If `$field` is not a safe identifier.
     */
    private function assertQueryableJsonFieldName(string $field): void
    {
        \Waaseyaa\EntityStorage\Query\JsonFieldName::assertQueryable($field);
    }

    /**
     * Returns the set of core field names whose registered storage hint is
     * FieldStorage::Data. Mirrors SqlEntityStorage::getDataStoredCoreFieldNames()
     * so the read path consults the same registry hint as the write path
     * (mission #1257 WP04, K2 — read/write symmetry for FieldStorage::Data).
     *
     * Result is memoized for the lifetime of this query instance; the
     * registry is invariant per request, and resolveField() may be called
     * once per condition + sort + select column.
     *
     * @return array<string, true>
     */
    private function getDataStoredCoreFieldNames(): array
    {
        if ($this->dataStoredCoreFieldNames !== null) {
            return $this->dataStoredCoreFieldNames;
        }

        if ($this->fieldRegistry === null) {
            return $this->dataStoredCoreFieldNames = [];
        }

        $names = [];
        foreach ($this->fieldRegistry->coreFieldsFor($this->entityType->id()) as $name => $definition) {
            if ($definition->getStored() === FieldStorage::Data) {
                $names[$name] = true;
            }
        }

        return $this->dataStoredCoreFieldNames = $names;
    }

    /**
     * Resolve (and cache) the {@see EntityAccessHandler} used by
     * {@see execute()}.
     *
     * The query intentionally avoids constructor DI for the handler (the
     * single factory site is `SqlEntityStorage::getQuery()` and we do not
     * want to thread access wiring through every storage subclass). Instead,
     * the production binding flows through {@see withAccessHandler()} (wired
     * by `getQuery()` per #1714). A caller that has not bound a handler gets an
     * empty handler whose `check()` returns Neutral for every entity; under
     * deny-by-default (audit C-6) Neutral is not Allowed, so such a query
     * returns nothing — fail-closed, never an unfiltered leak.
     */
    private function resolveAccessHandler(): EntityAccessHandler
    {
        return $this->accessHandler ??= new EntityAccessHandler();
    }

    /**
     * Hydrate the candidate IDs into entities, run the per-row access check,
     * and return survivors as a list of IDs (or, when {@see $isCount} is
     * true, as `[count($survivors)]`).
     *
     * Centralises the post-SQL filter so that {@see execute()} and the
     * `count()` branch share one machinery — no duplicated SQL count path,
     * no risk that the cardinality and the page diverge.
     *
     * @param array<int, int|string> $candidateIds
     * @return array<int, int|string>
     */
    private function filterCandidates(array $candidateIds): array
    {
        if ($candidateIds === []) {
            return $this->isCount ? [0] : [];
        }

        // FR-007: the candidate window is the unfiltered SQL `LIMIT/OFFSET`
        // window. Hydration here does not advance the cursor — successive
        // pages still index by `$offset + $limit`, not by survivor count.
        $entities = $this->entityLoader !== null
            ? ($this->entityLoader)($candidateIds)
            : [];

        // Deny-by-default (audit C-6): an access-checked query must PROVE each
        // row is viewable before returning it. When no hydrator is wired (or it
        // yields nothing) we cannot run the per-row check, so we cannot prove
        // any candidate is allowed — fail closed and drop the whole window
        // rather than leaking it unfiltered. Post-#1714, production always binds
        // the loader via SqlEntityStorage::getQuery(); this guards the
        // direct-construction / misconfiguration path that previously passed
        // candidate IDs through open-by-default.
        if ($entities === []) {
            return $this->isCount ? [0] : [];
        }

        $handler = $this->resolveAccessHandler();
        $account = $this->account;
        \assert($account !== null, 'Account must be bound; checked in execute() before filterCandidates() is called.');

        $survivors = [];
        // Preserve the SQL-side ordering — iterate candidate IDs, not the
        // hydrator's return order (which may be id-keyed and lose order).
        foreach ($candidateIds as $id) {
            $entity = $entities[$id] ?? null;
            if (!$entity instanceof EntityInterface) {
                // Loader did not return an entity for this id (row vanished
                // between SQL and hydration, or the loader filtered it out).
                // Drop the row defensively — a missing entity cannot be
                // proved allowed.
                continue;
            }

            if ($handler->check($entity, 'view', $account)->isAllowed()) {
                $survivors[] = $id;
            }
        }

        if ($this->isCount) {
            return [\count($survivors)];
        }

        return $survivors;
    }

    /**
     * Coerce a condition value to its declared FieldDefinition type so that
     * comparisons against `_data` JSON storage commute regardless of how
     * the caller typed the bound parameter (mission #1257 WP05, K3).
     *
     * SQLite's `json_extract()` returns the native JSON type (integer for
     * `13`, string for `"13"`) and SQLite has no column affinity for
     * expression results — so `WHERE json_extract(_data, '$.x') = '13'`
     * matches no rows when the stored value is integer 13. Coercing the
     * bound parameter to the registered field type closes the asymmetry
     * and lets callers bind `int|string|null` interchangeably without
     * needing to know the storage shape (#1257 anchor).
     *
     * Coercion is a no-op when the registry has no definition for the
     * field, when the field's declared type is non-numeric, or when the
     * value is not a numeric-looking string. Boolean coercion is
     * intentionally left out: PHP/SQL boolean-string conventions vary
     * (`'true'`, `'1'`, `'on'`) and forcing a single answer here would
     * surprise callers.
     */
    private function coerceConditionValue(string $field, mixed $value, ?string $bundle): mixed
    {
        if ($this->fieldRegistry === null) {
            return $value;
        }

        $definition = $this->lookupFieldDefinition($field, $bundle);
        if ($definition === null) {
            return $value;
        }

        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }

        return match ($definition->getType()) {
            'integer', 'int' => (int) $value,
            'float', 'decimal', 'numeric' => (float) $value,
            default => $value,
        };
    }

    /**
     * Look up the FieldDefinition for a referenced field, honoring the
     * routing bundle when the field is bundle-scoped.
     */
    private function lookupFieldDefinition(string $field, ?string $bundle): ?FieldDefinitionInterface
    {
        if ($this->fieldRegistry === null) {
            return null;
        }

        $entityTypeId = $this->entityType->id();

        if ($bundle === null) {
            $core = $this->fieldRegistry->coreFieldsFor($entityTypeId);
            return $core[$field] ?? null;
        }

        $bundleFields = $this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle);
        return $bundleFields[$field] ?? null;
    }

    /**
     * Resolve every referenced field to a routing target before SQL emission.
     *
     * Returns a tuple of the field→bundle map and the set of bundles whose
     * subtables must be joined. Throws per the spec's ambiguity and
     * unknown-field rules. When no registry is present, or when the registry
     * has no entries for this entity type, returns an empty routing so that
     * callers fall back to the legacy unqualified behavior.
     *
     * @return array{routing: array<string, ?string>, requiredJoins: list<string>}
     */
    private function routeFields(): array
    {
        if ($this->fieldRegistry === null) {
            return ['routing' => [], 'requiredJoins' => []];
        }

        $entityTypeId = $this->entityType->id();
        $coreFields = $this->fieldRegistry->coreFieldsFor($entityTypeId);
        $bundleNames = $this->fieldRegistry->bundleNamesFor($entityTypeId);

        if ($coreFields === [] && $bundleNames === []) {
            return ['routing' => [], 'requiredJoins' => []];
        }

        $impliedBundle = $this->determineImpliedBundle($bundleNames);

        $routing = [];
        $requiredJoins = [];

        $referenced = [];
        foreach ($this->conditions as $c) {
            $referenced[$c['field']] = true;
        }
        foreach ($this->sorts as $s) {
            $referenced[$s['field']] = true;
        }

        foreach (array_keys($referenced) as $name) {
            if ($name === $this->bundleKey || \array_key_exists($name, $coreFields)) {
                $routing[$name] = null;
                // A core field marked FieldStorage::Data lives in the base
                // table's `_data` JSON blob, not in a column. resolveField()
                // consults getDataStoredCoreFieldNames() before fieldExists()
                // to honor the registry hint even when a legacy column
                // lingers (mission #1257 WP04, K2 — read/write symmetry).
                continue;
            }

            $bundlesDefining = $this->fieldRegistry->bundlesDefiningField($entityTypeId, $name);

            if ($bundlesDefining === []) {
                // Fallback: accept any name that is a base-table schema column.
                // This mirrors the ContentEntityBase registry fallback invariant
                // (§Resolution) — EntityType keys (id, uuid, bundle, label,
                // langcode) and any columns declared via EntityType::fieldDefinitions
                // remain queryable even when the type has not been fully
                // registered through EntityTypeManager. _data blob fields, by
                // contrast, are only queryable once explicitly registered.
                if ($this->database->schema()->fieldExists($this->tableName, $name)) {
                    $routing[$name] = null;
                    continue;
                }

                throw new UnknownFieldException(\sprintf(
                    'Field "%s" is not registered for entity type "%s". '
                    . 'Declare it as a core field on the EntityType or register it '
                    . 'via FieldDefinitionRegistry::registerBundleFields().',
                    $name,
                    $entityTypeId,
                ));
            }

            if (\count($bundlesDefining) === 1) {
                $target = $bundlesDefining[0];
            } elseif ($impliedBundle !== null && \in_array($impliedBundle, $bundlesDefining, true)) {
                $target = $impliedBundle;
            } else {
                throw BundleAmbiguousFieldException::forField(
                    $name,
                    $entityTypeId,
                    $bundlesDefining,
                    $this->bundleKey ?? 'bundle',
                );
            }

            $routing[$name] = $target;
            $requiredJoins[$target] = true;
        }

        return [
            'routing' => $routing,
            'requiredJoins' => array_keys($requiredJoins),
        ];
    }

    /**
     * Derive the single bundle (if any) that the query's conditions narrow to.
     *
     * Each condition either narrows the allowed bundle set (explicit bundle-key
     * condition, or a condition on a field that only one bundle registers) or
     * leaves it untouched. When the intersection collapses to exactly one
     * bundle, that bundle is the implied bundle used to resolve otherwise-
     * ambiguous references — per §Query "another bundle-scoped condition in
     * the same query that implies the bundle uniquely."
     *
     * @param array<int, string> $bundleNames
     */
    private function determineImpliedBundle(array $bundleNames): ?string
    {
        if ($bundleNames === [] || $this->fieldRegistry === null) {
            return null;
        }

        $allowed = array_fill_keys($bundleNames, true);

        foreach ($this->conditions as $c) {
            $narrowed = null;
            $op = strtoupper($c['operator']);
            $value = $c['value'];
            $field = $c['field'];

            if ($field === $this->bundleKey) {
                if (($op === '=' || $op === '==') && \is_string($value)) {
                    $narrowed = [$value];
                } elseif ($op === 'IN' && \is_array($value)) {
                    $strings = array_values(array_filter($value, 'is_string'));
                    if ($strings !== []) {
                        $narrowed = $strings;
                    }
                }
            } else {
                $defining = $this->fieldRegistry->bundlesDefiningField(
                    $this->entityType->id(),
                    $field,
                );
                if (\count($defining) === 1) {
                    $narrowed = $defining;
                }
            }

            if ($narrowed === null) {
                continue;
            }

            $allowed = array_intersect_key($allowed, array_fill_keys($narrowed, true));
            if ($allowed === []) {
                return null;
            }
        }

        if (\count($allowed) !== 1) {
            return null;
        }

        return array_key_first($allowed);
    }

    /**
     * Deterministic fingerprint for {@see SqlEntityQueryResultCache}.
     *
     * Security (C-010): the cached result of a query is a function of the
     * access dimension as well as the SQL shape. An access-filtered list is
     * account-specific, so the account MUST discriminate the key when access
     * checking is on — otherwise account B can be served account A's filtered
     * survivors, or a system-context accessCheck(false) unfiltered list can be
     * served to an access-checked caller (cross-account / filter-bypass leak).
     * We always fold in accessCheckEnabled; we fold in a per-account
     * discriminator only when access checking is on. When it is off the result
     * is account-independent, so every accessCheck(false) caller emits the same
     * `account => null` and legitimately shares one cache key. The throw at the
     * top of execute() guarantees accessCheckEnabled is never true with a null
     * account, so the discriminator is always present when it matters.
     */
    private function buildCacheFingerprint(): string
    {
        $payload = [
            'conditions' => $this->conditions,
            'sorts' => $this->sorts,
            'rangeOffset' => $this->rangeOffset,
            'rangeLimit' => $this->rangeLimit,
            'isCount' => $this->isCount,
            'accessCheck' => $this->accessCheckEnabled,
            'account' => $this->accessCheckEnabled && $this->account !== null
                ? [$this->account->id(), $this->account::class]
                : null,
        ];

        return hash('xxh128', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Execute the query and return entity IDs.
     *
     * When `count()` has been called, returns a single-element array with the
     * cardinality (post-filter when access checking is enabled, pre-filter
     * when bypassed).
     *
     * Security contract (FR-005 / C-006): when access checking is enabled
     * and no account is bound, throws {@see MissingQueryAccountException}
     * BEFORE any database work — the throw is the first statement in this
     * method so callers fail closed without any observable side effect.
     *
     * @return array<int|string>
     */
    public function execute(): array
    {
        // FR-005 / C-006: fail closed. The throw precedes every other side
        // effect (no SQL, no cache lookup, no logging) so callers cannot
        // observe a partial result without an authenticated principal.
        if ($this->accessCheckEnabled && $this->account === null) {
            throw MissingQueryAccountException::forQuery($this->entityType);
        }

        $entityTypeId = $this->entityType->id();
        $fingerprint = $this->resultCache !== null ? $this->buildCacheFingerprint() : null;

        if ($fingerprint !== null) {
            $cached = $this->resultCache->get($entityTypeId, $fingerprint);
            if ($cached !== null) {
                return $cached;
            }
        }

        $routed = $this->routeFields();
        $routing = $routed['routing'];
        $requiredJoins = $routed['requiredJoins'];

        $select = $this->database->select($this->tableName);

        // When access checking is enabled we always materialize candidate IDs
        // (so the filter can run on hydrated rows) and compute count() in PHP
        // from the survivor list. When bypassed, the existing SQL COUNT(*)
        // fast path is preserved.
        $useSqlCount = $this->isCount && !$this->accessCheckEnabled;

        if ($useSqlCount) {
            $select = $select->countQuery();
        } else {
            $select = $select->addField($this->tableName, $this->idKey);
        }

        foreach ($requiredJoins as $bundle) {
            $subtable = SqlSchemaHandler::resolveSubtableName($this->tableName, $bundle, $this->entityType->id());
            $baseQuoted = $this->database->quoteIdentifier($this->tableName);
            $subQuoted = $this->database->quoteIdentifier($subtable);
            $select = $select->join(
                $subtable,
                $subtable,
                $baseQuoted . '.' . $this->idKey . ' = ' . $subQuoted . '.' . $this->idKey,
            );
        }

        // Apply conditions.
        //
        // WP6: resolveField() now returns a ResolvedField. An *identifier*
        // (column / qualified `table.field`) flows through condition()/isNull()/
        // isNotNull(), which auto-quote it. An *expression* (json_extract(...))
        // flows through whereRaw(), which emits it verbatim — quoting an
        // expression would corrupt it. The K3 CAST/coercion logic is preserved
        // inside the expression path.
        foreach ($this->conditions as $condition) {
            $operator = strtoupper($condition['operator']);
            $fieldName = $condition['field'];
            $bundle = $routing[$fieldName] ?? null;
            $resolved = $this->resolveField($fieldName, $routing);
            $field = $resolved->sql();
            $isExpr = $resolved->isExpression();

            if ($operator === 'IS NULL') {
                $select = $isExpr
                    ? $select->whereRaw($field . ' IS NULL')
                    : $select->isNull($field);
            } elseif ($operator === 'IS NOT NULL') {
                $select = $isExpr
                    ? $select->whereRaw($field . ' IS NOT NULL')
                    : $select->isNotNull($field);
            } elseif ($operator === 'IN') {
                $rawValues = is_array($condition['value']) ? $condition['value'] : [$condition['value']];
                if ($resolved->isJsonExtract()) {
                    // K3 (mission #1257 WP05): SQLite's `json_extract()` returns
                    // the native JSON type and the underlying DBAL helper
                    // hardcodes ArrayParameterType::STRING for IN-set parameters.
                    // Wrapping the resolved field in CAST(... AS TEXT) and
                    // stringifying each value forces text-vs-text equality so
                    // callers can pass int|string|null interchangeably without
                    // knowing the storage shape.
                    $values = array_map(static fn(mixed $v): string => (string) $v, $rawValues);
                    $select = $select->whereRaw('CAST(' . $field . ' AS TEXT) IN (?)', [$values]);
                } else {
                    $values = array_map(
                        fn(mixed $v): mixed => $this->coerceConditionValue($fieldName, $v, $bundle),
                        $rawValues,
                    );
                    $select = $isExpr
                        ? $select->whereRaw($field . ' IN (?)', [$values])
                        : $select->condition($field, $values, 'IN');
                }
            } elseif ($operator === 'CONTAINS') {
                // String-pattern operator: do not coerce, callers want string semantics.
                $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $condition['value']) . '%';
                $select = $isExpr
                    ? $select->whereRaw($field . " LIKE ? ESCAPE '\\'", [$pattern])
                    : $select->condition($field, $pattern, 'LIKE');
            } elseif ($operator === 'STARTS_WITH') {
                $pattern = str_replace(['%', '_'], ['\\%', '\\_'], (string) $condition['value']) . '%';
                $select = $isExpr
                    ? $select->whereRaw($field . " LIKE ? ESCAPE '\\'", [$pattern])
                    : $select->condition($field, $pattern, 'LIKE');
            } else {
                $value = $this->coerceConditionValue($fieldName, $condition['value'], $bundle);
                if (!$isExpr) {
                    $select = $select->condition($field, $value, $condition['operator']);
                } elseif ($operator === 'LIKE' || $operator === 'NOT LIKE') {
                    $select = $select->whereRaw($field . ' ' . $operator . " ? ESCAPE '\\'", [$value]);
                } else {
                    $select = $select->whereRaw($field . ' ' . $operator . ' ?', [$value]);
                }
            }
        }

        // Apply sorts. Identifiers auto-quote via orderBy(); json_extract(...)
        // expressions emit verbatim via orderByRaw() (WP6).
        foreach ($this->sorts as $sort) {
            $resolved = $this->resolveField($sort['field'], $routing);
            $select = $resolved->isExpression()
                ? $select->orderByRaw($resolved->sql(), $sort['direction'])
                : $select->orderBy($resolved->sql(), $sort['direction']);
        }

        // Apply range.
        if ($this->rangeLimit !== null) {
            $select = $select->range($this->rangeOffset ?? 0, $this->rangeLimit);
        }

        $result = $select->execute();

        if ($useSqlCount) {
            // Bypass fast path: SQL COUNT(*) without hydration. C-004 / FR-004.
            $countResult = [0];
            foreach ($result as $row) {
                $row = (array) $row;
                $countResult = [(int) ($row['count'] ?? 0)];
                break;
            }
            if ($fingerprint !== null) {
                $this->resultCache->set($entityTypeId, $fingerprint, $countResult);
            }

            return $countResult;
        }

        $ids = [];
        foreach ($result as $row) {
            $row = (array) $row;
            $id = $row[$this->idKey];
            // Preserve integer IDs as integers.
            if (is_numeric($id) && (int) $id == $id) {
                $id = (int) $id;
            }
            $ids[] = $id;
        }

        if (!$this->accessCheckEnabled) {
            // C-004 bypass: skip hydration entirely and return candidate IDs
            // (or candidate count, when isCount && bypassed — handled above
            // via SQL COUNT).
            if ($fingerprint !== null) {
                $this->resultCache->set($entityTypeId, $fingerprint, $ids);
            }

            return $ids;
        }

        // Slow path: hydrate the candidate window, run per-row
        // EntityAccessHandler::check(), drop Forbidden rows. count() reuses
        // this machinery — no duplicated SQL count branch (FR-006).
        $filtered = $this->filterCandidates($ids);

        if ($fingerprint !== null) {
            $this->resultCache->set($entityTypeId, $fingerprint, $filtered);
        }

        return $filtered;
    }
}
