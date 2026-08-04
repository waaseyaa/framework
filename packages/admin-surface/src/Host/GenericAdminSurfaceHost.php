<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Action\SurfaceActionHandlerInterface;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Query\SurfaceFilterOperator;
use Waaseyaa\AdminSurface\Query\SurfaceQuery;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\SlugGenerator;
use Waaseyaa\Workflows\Binding\WorkflowBindingResolver;

/**
 * Generic admin surface host that works with any Waaseyaa application.
 *
 * Auto-discovers entity types from EntityTypeManagerInterface and provides full
 * CRUD operations. Apps get a working admin SPA without writing a custom
 * host — just install the admin-surface package.
 *
 * For custom behavior, extend this class and override individual methods,
 * or implement AbstractAdminSurfaceHost directly.
 */
class GenericAdminSurfaceHost extends AbstractAdminSurfaceHost
{
    /**
     * Shipped migration bookkeeping that is never part of node authoring.
     *
     * This floor belongs to the generic host rather than its service provider:
     * applications may own the admin routes and construct this host directly.
     * Host-supplied additions are merged with it below.
     *
     * @var array<string, list<string>>
     */
    private const array DEFAULT_INTERNAL_FIELDS_BY_TYPE = [
        'node' => ['source_status', 'wp_status'],
    ];

    /**
     * Structural filter/sort floor, mirrored from
     * {@see \Waaseyaa\Api\ResourceSerializer::ALWAYS_INTERNAL_FIELDS} and
     * {@see \Waaseyaa\Api\JsonApiController::ALWAYS_INTERNAL_FIELDS}. Credential
     * material (e.g. `pass`) must be structurally unfilterable/unsortable even
     * before any field-access policy runs (R13 WP1).
     *
     * @var list<string>
     */
    private const array ALWAYS_INTERNAL_FIELDS = ['pass', 'password', 'password_hash'];

    /** Config rows with an explicitly reviewed generic edit/delete lifecycle. */
    private const array MUTABLE_CONFIG_ROW_TYPES = ['taxonomy_vocabulary'];

    /**
     * Hard cap on the session capability allowlist. The projection exists so
     * the SPA can gate a handful of admin affordances, not to mirror the
     * account's permission universe — an app that hits this cap is enumerating
     * permissions, which this surface deliberately refuses.
     */
    public const int CAPABILITY_ALLOWLIST_MAX = 32;

    /** Hard cap on a single allowlisted permission identifier's length. */
    public const int CAPABILITY_IDENTIFIER_MAX_LENGTH = 128;

    /** @var \Waaseyaa\Access\AuthorizationPrincipalInterface|null */
    private ?AccountInterface $currentAccount = null;

    /** @var array<string, SurfaceActionHandlerInterface> */
    protected array $actions = [];

    private readonly EntityClockInterface $clock;

    /** @var list<string> */
    private readonly array $capabilityAllowlist;

    /**
     * @param string[]          $readOnlyTypes Entity type IDs that should be read-only in the admin
     * @param array<string, bool> $features Installed capabilities exposed to the SPA session
     * @param array<string, list<string>> $internalFieldsByType Additional host-owned migration or operational fields omitted from forms
     * @param list<string> $capabilityAllowlist Explicit permission identifiers to project into the
     *   session as `capabilities` (each evaluated via hasPermission() on the resolved principal).
     *   Only these identifiers are ever serialized; the list is deduplicated, sorted, and bounded
     *   (CAPABILITY_ALLOWLIST_MAX / CAPABILITY_IDENTIFIER_MAX_LENGTH). A malformed or oversized
     *   allowlist is rejected with InvalidArgumentException at construction. Default: empty.
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?SchemaPresenter $schemaPresenter = null,
        private readonly string $tenantId = 'default',
        private readonly string $tenantName = 'Waaseyaa',
        private readonly string $adminPermission = 'administer content',
        private readonly array $readOnlyTypes = [],
        private readonly ?WorkflowBindingResolver $workflowBindingResolver = null,
        private readonly array $features = [],
        private readonly array $internalFieldsByType = [],
        ?EntityClockInterface $clock = null,
        array $capabilityAllowlist = [],
    ) {
        $this->clock = $clock ?? new UtcEntityClock();
        $this->capabilityAllowlist = self::normalizeCapabilityAllowlist($capabilityAllowlist);
    }

    /**
     * Validate and canonicalize the session capability allowlist: strings only,
     * bounded length, printable identifier characters, deduplicated, and sorted
     * so the projection is deterministic regardless of configuration order.
     *
     * @return list<string>
     */
    private static function normalizeCapabilityAllowlist(array $capabilityAllowlist): array
    {
        $normalized = [];
        foreach ($capabilityAllowlist as $permission) {
            if (!is_string($permission)) {
                throw new \InvalidArgumentException(
                    'Capability allowlist entries must be permission identifier strings, got ' . get_debug_type($permission) . '.',
                );
            }
            if ($permission === '' || strlen($permission) > self::CAPABILITY_IDENTIFIER_MAX_LENGTH) {
                throw new \InvalidArgumentException(sprintf(
                    'Capability allowlist entries must be 1–%d characters long.',
                    self::CAPABILITY_IDENTIFIER_MAX_LENGTH,
                ));
            }
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._:-]*$/', $permission) !== 1) {
                // Constraint-only message: configuration can be malformed with
                // secret-like input, so the offending value is never echoed.
                throw new \InvalidArgumentException(
                    'Capability allowlist entries must match ^[A-Za-z0-9][A-Za-z0-9 ._:-]*$; an entry does not.',
                );
            }
            $normalized[$permission] = true;
        }

        if (count($normalized) > self::CAPABILITY_ALLOWLIST_MAX) {
            throw new \InvalidArgumentException(sprintf(
                'Capability allowlist exceeds the maximum of %d permissions (%d given); the session projection is not a permission enumeration surface.',
                self::CAPABILITY_ALLOWLIST_MAX,
                count($normalized),
            ));
        }

        $permissions = array_keys($normalized);
        sort($permissions, SORT_STRING);

        return $permissions;
    }

    public function resolveSession(Request $request): ?AdminSurfaceSessionData
    {
        $account = $request->attributes->get('_account');
        $principal = DecisionAccountResolver::resolve(
            $request->attributes->get('_authorization_principal'),
            $account,
        );

        if (!$account instanceof AccountInterface || $principal === null) {
            return null;
        }

        if (!$principal->hasPermission($this->adminPermission)) {
            return null;
        }

        $this->currentAccount = $principal;

        // Server-authoritative capability projection: ask the resolved
        // principal about exactly the allowlisted permissions — never client
        // data, display roles, or the account's wider permission universe.
        $capabilities = [];
        foreach ($this->capabilityAllowlist as $permission) {
            $capabilities[$permission] = $principal->hasPermission($permission);
        }

        return new AdminSurfaceSessionData(
            accountId: (string) $principal->id(),
            accountName: 'Admin',
            roles: $principal->getRoles(),
            policies: [],
            tenantId: $this->tenantId,
            tenantName: $this->tenantName,
            features: $this->features,
            ui: $this->buildAdminUi($principal),
            capabilities: $capabilities,
        );
    }

    /**
     * Override to inject header links and/or sidebar items into the admin SPA session.
     *
     * Return null or an empty payload to omit the `ui` key from JSON.
     */
    protected function buildAdminUi(AccountInterface $account): ?AdminSurfaceUiPayload
    {
        return null;
    }

    public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
    {
        $catalog = new CatalogBuilder();

        foreach ($this->entityTypeManager->getDefinitions() as $definition) {
            $entity = $catalog->defineEntity($definition->id(), $definition->getLabel());

            $description = $definition->getDescription();
            if ($description !== null) {
                $entity->description($description);
            }

            $group = $definition->getGroup();
            if ($group !== null) {
                $entity->group($group);
            }

            $referenceField = $this->safeReferenceLabelField($definition);
            if ($referenceField !== null) {
                $entity->reference(
                    labelField: $referenceField,
                    searchField: $referenceField,
                    sortField: $referenceField,
                );
            }

            foreach ($definition->getFieldDefinitions() as $name => $fieldDef) {
                $entity->field(
                    $name,
                    $fieldDef['label'] ?? $name,
                    $fieldDef['type'] ?? 'string',
                );
            }

            $isConfig = is_subclass_of($definition->getClass(), ConfigEntityBase::class);
            $isMutableConfigRow = $isConfig && in_array($definition->id(), self::MUTABLE_CONFIG_ROW_TYPES, true);
            $isReadOnly = in_array($definition->id(), $this->readOnlyTypes, true)
                || ($isConfig && !$isMutableConfigRow);

            if ($isReadOnly) {
                $entity->capabilities([
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                ]);
            } else {
                if ($isMutableConfigRow) {
                    $entity->capabilities(['create' => false]);
                }
                $entity->action('delete', 'Delete')
                    ->confirm('Are you sure you want to delete this item?')
                    ->dangerous();
            }
        }

        return $catalog;
    }

    /**
     * Resolve reference metadata from the entity type's canonical label key.
     *
     * This is structural metadata only. Entity and field access remain
     * enforced by list(); unsafe/internal fields are omitted rather than
     * advertised and clients must not guess a replacement.
     */
    private function safeReferenceLabelField(EntityTypeInterface $definition): ?string
    {
        $labelField = $definition->getKeys()['label'] ?? null;
        if (!is_string($labelField)
            || $labelField === ''
            || preg_match('/^[A-Za-z0-9_]+$/', $labelField) !== 1
            || in_array($labelField, self::ALWAYS_INTERNAL_FIELDS, true)) {
            return null;
        }

        $fieldDefinition = $definition->getFieldDefinitions()[$labelField] ?? null;
        if ($fieldDefinition !== null && $fieldDefinition->getSetting('internal') === true) {
            return null;
        }

        return $labelField;
    }

    public function list(string $type, SurfaceQuery|array $query = []): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        // Backward compat: convert plain array to SurfaceQuery with pagination only
        if (is_array($query)) {
            $offset = max(0, (int) ($query['page[offset]'] ?? $query['page']['offset'] ?? 0));
            $limit = (int) ($query['page[limit]'] ?? $query['page']['limit'] ?? 50);
            $query = new SurfaceQuery(offset: $offset, limit: $limit);
        }

        // R13 WP1, layer (a): structural filter/sort allowlist, mirroring
        // JsonApiController::validateQueryFields() (the REST "audit R2 WP1"
        // gate). Runs before anything is read so an unknown/credential field
        // name can never reach applyFilter()/the sort comparator. Layer (b)
        // (per-entity field-view access, below) catches what a static
        // allowlist cannot express.
        $fieldError = $this->validateSurfaceQueryFields($type, $query);
        if ($fieldError !== null) {
            return $fieldError;
        }

        // Fail closed: without an access handler AND a resolved account we cannot
        // make a per-entity access decision, so expose nothing rather than leak
        // unchecked entities.
        if ($this->accessHandler === null || $this->currentAccount === null) {
            return AdminSurfaceResultData::success([
                'entities' => [],
                'total' => 0,
                'offset' => $query->offset,
                'limit' => $query->limit,
            ]);
        }

        $repository = $this->entityTypeManager->getRepository($type);

        // Config entities are small, identity-keyed sets. Their query-level
        // access projection is intentionally content-oriented and can return
        // an empty survivor set for role-gated config policies even when the
        // authoritative hydrated policy allows the administrator. Evaluate
        // these bounded rows in memory through the same entity/field access
        // checks used after ordinary queries so dashboard bundle definitions
        // remain visible without weakening authorization.
        if (is_a($this->entityTypeManager->getDefinition($type)->getClass(), ConfigEntityBase::class, true)) {
            $entities = array_values(array_filter(
                $repository->findBy([]),
                fn($entity): bool => $this->accessHandler->check($entity, 'view', $this->currentAccount)->isAllowed(),
            ));
            foreach ($query->filters as $filter) {
                $entities = array_values(array_filter(
                    $entities,
                    fn($entity): bool => $this->applyFilter($entity, $filter['field'], $filter['operator'], $filter['value']),
                ));
            }
            if ($query->sortField !== null) {
                foreach ($entities as $entity) {
                    if ($this->isFieldViewForbidden($entity, $query->sortField)) {
                        return AdminSurfaceResultData::error(400, 'Invalid sort field', "Cannot sort by field '{$query->sortField}'.");
                    }
                }
                self::sortEntities($entities, $query->sortField, $query->sortDirection === 'DESC');
            }
            $total = count($entities);
            $entities = array_slice($entities, $query->offset, $query->limit);
            $serializer = $this->serializer();
            $rows = [];
            foreach ($entities as $entity) {
                $row = $this->jsonApiResourceToSurfaceEntity(
                    $serializer->serialize($entity, $this->accessHandler, $this->currentAccount),
                );
                $isMutableConfigRow = in_array($type, self::MUTABLE_CONFIG_ROW_TYPES, true)
                    && !in_array($type, $this->readOnlyTypes, true);
                $row['capabilities'] = [
                    'view' => true,
                    'edit' => $isMutableConfigRow
                        && $this->accessHandler->check($entity, 'update', $this->currentAccount)->isAllowed(),
                    // Mutable config rows keep the delete affordance visible even
                    // when an entity-state guard (for example, referenced terms)
                    // will refuse this particular attempt with an actionable reason.
                    // The action endpoint remains the authoritative access boundary.
                    'delete' => $isMutableConfigRow,
                ];
                $rows[] = $row;
            }

            return AdminSurfaceResultData::success([
                'entities' => $rows,
                'total' => $total,
                'offset' => $query->offset,
                'limit' => $query->limit,
            ]);
        }

        // Field access can vary per entity, so resolve the set of IDs whose
        // queried fields are viewable before caller-controlled conditions,
        // ordering, or pagination shape SQL. A Forbidden filter field excludes
        // that entity from the query scope; a Forbidden sort rejects the whole
        // sort value-independently, matching the established surface contract.
        $queryScopeIds = null;
        $filterScopeEntities = [];
        $useInMemoryBundleQuery = $this->queryUsesMultiBundleScopedField($type, $query);
        if ($query->filters !== [] || $query->sortField !== null) {
            $scopeIds = $repository->getQuery()->setAccount($this->currentAccount)->execute();
            $scopeEntities = array_values(array_filter(
                $repository->findMany($scopeIds),
                fn($entity): bool => $this->accessHandler->check($entity, 'view', $this->currentAccount)->isAllowed(),
            ));

            if ($query->sortField !== null) {
                foreach ($scopeEntities as $entity) {
                    if ($this->isFieldViewForbidden($entity, $query->sortField)) {
                        return AdminSurfaceResultData::error(400, 'Invalid sort field', "Cannot sort by field '{$query->sortField}'.");
                    }
                }
            }

            $filterScopeEntities = array_values(array_filter(
                $scopeEntities,
                function ($entity) use ($query): bool {
                    foreach ($query->filters as $filter) {
                        if ($this->isFieldViewForbidden($entity, $filter['field'])) {
                            return false;
                        }
                    }

                    return true;
                },
            ));

            if ($filterScopeEntities === [] && $query->filters !== []) {
                return AdminSurfaceResultData::success([
                    'entities' => [],
                    'total' => 0,
                    'offset' => $query->offset,
                    'limit' => $query->limit,
                ]);
            }

            // Keep the unscoped SQL fast path when every queried filter field
            // is viewable. Only add an ID scope when field access actually
            // excludes entities; this avoids oversized IN lists on ordinary
            // large-table filters while retaining full SQL pushdown.
            if (count($filterScopeEntities) !== count($scopeEntities)) {
                $queryScopeIds = array_map(
                    static fn($entity): int|string => $entity->id(),
                    $filterScopeEntities,
                );
            }
        }

        if ($useInMemoryBundleQuery) {
            // SQL storage deliberately rejects ambiguous fields shared by
            // several bundle subtables. Field-access preflight already loaded
            // this bounded authoritative bundle scope, so finish the common
            // field query in memory without duplicate loads or unsafe routing.
            $pageEntities = array_values(array_filter(
                $filterScopeEntities,
                function ($entity) use ($query): bool {
                    foreach ($query->filters as $filter) {
                        if (!$this->filterValueMatches($entity->get($filter['field']), $filter['operator'], $filter['value'])) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
            if ($query->sortField !== null) {
                self::sortEntities($pageEntities, $query->sortField, $query->sortDirection === 'DESC');
            }
            $total = count($pageEntities);
            $pageEntities = array_slice($pageEntities, $query->offset, $query->limit);
            $totalResult = [$total];
        } else {
            // Push ordinary filter, sort, and page work into the existing
            // entity-query SQL machinery.
            $idKey = $this->entityTypeManager->getDefinition($type)->getKeys()['id'] ?? 'id';
            $pageQuery = $this->applySurfaceQuery($repository->getQuery()->setAccount($this->currentAccount), $query, true, $queryScopeIds, $idKey);
            $pageIds = $pageQuery->execute();

            // Access-checked count queries return `[survivorCount]`, not survivor IDs.
            $totalQuery = $this->applySurfaceQuery($repository->getQuery()->setAccount($this->currentAccount), $query, false, $queryScopeIds, $idKey);
            $totalResult = $totalQuery->count()->execute();
            $total = (int) ($totalResult[0] ?? 0);
            $pageEntities = $repository->findMany($pageIds);
        }
        if (!$useInMemoryBundleQuery) {
            $pageEntities = array_values(array_filter(
                $pageEntities,
                fn($entity): bool => $this->accessHandler->check($entity, 'view', $this->currentAccount)->isAllowed(),
            ));
            foreach ($query->filters as $filter) {
                $pageEntities = array_values(array_filter(
                    $pageEntities,
                    fn($entity): bool => $this->applyFilter($entity, $filter['field'], $filter['operator'], $filter['value']),
                ));
            }

            // Repository adapters need not preserve input-ID order.
            if ($query->sortField !== null) {
                self::sortEntities($pageEntities, $query->sortField, $query->sortDirection === 'DESC');
            }
        }

        // Keep the response internally coherent for repository adapters that
        // can hydrate a page while reporting an empty count result.
        if ($totalResult === [] && $pageEntities !== []) {
            $total = count($pageEntities);
        }

        $serializer = $this->serializer();

        $surfaceEntities = [];
        foreach ($pageEntities as $entity) {
            $surfaceEntity = $this->jsonApiResourceToSurfaceEntity(
                $serializer->serialize($entity, $this->accessHandler, $this->currentAccount),
            );
            $surfaceEntity['capabilities'] = [
                // Reuse the authoritative view decision that admitted this row;
                // repeating it here can amplify expensive policy checks.
                'view' => true,
                'edit' => !in_array($type, $this->readOnlyTypes, true)
                    && $this->accessHandler->check($entity, 'update', $this->currentAccount)->isAllowed(),
                'delete' => !in_array($type, $this->readOnlyTypes, true)
                    && $this->accessHandler->check($entity, 'delete', $this->currentAccount)->isAllowed(),
            ];
            $surfaceEntities[] = $surfaceEntity;
        }

        return AdminSurfaceResultData::success([
            'entities' => $surfaceEntities,
            'total' => $total,
            'offset' => $query->offset,
            'limit' => $query->limit,
        ]);
    }

    /**
     * @param list<int|string>|null $queryScopeIds
     */
    private function applySurfaceQuery(\Waaseyaa\Entity\Storage\EntityQueryInterface $entityQuery, SurfaceQuery $query, bool $paginate, ?array $queryScopeIds = null, string $idKey = 'id'): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        if ($queryScopeIds !== null) {
            $entityQuery->condition($idKey, $queryScopeIds, 'IN');
        }

        foreach ($query->filters as $filter) {
            $value = $filter['value'];
            if ($filter['operator'] === SurfaceFilterOperator::IN && is_string($value)) {
                $value = explode(',', $value);
            }
            $operator = match ($filter['operator']) {
                SurfaceFilterOperator::EQUALS => '=',
                SurfaceFilterOperator::NOT_EQUALS => '!=',
                SurfaceFilterOperator::CONTAINS => 'CONTAINS',
                SurfaceFilterOperator::STARTS_WITH => 'STARTS_WITH',
                SurfaceFilterOperator::IN => 'IN',
                SurfaceFilterOperator::GT => '>',
                SurfaceFilterOperator::LT => '<',
                SurfaceFilterOperator::GTE => '>=',
                SurfaceFilterOperator::LTE => '<=',
            };
            $entityQuery->condition($filter['field'], $value, $operator);
        }

        if ($paginate && $query->sortField !== null) {
            $entityQuery->sort($query->sortField, $query->sortDirection);
        }
        if ($paginate) {
            $entityQuery->range($query->offset, $query->limit);
        }

        return $entityQuery;
    }

    private function applyFilter(mixed $entity, string $field, SurfaceFilterOperator $operator, mixed $value): bool
    {
        // R13 WP1, layer (b): field access is per-entity (e.g. classification/
        // clearance-gated fields differ by entity), so it must be evaluated per
        // entity here rather than once for the whole request. Fail closed: a
        // Forbidden field is never read to decide match/no-match. The entity
        // is simply excluded, regardless of operator or filter value, so no
        // value-derived signal (a presence/absence oracle) can leak.
        if ($this->isFieldViewForbidden($entity, $field)) {
            return false;
        }

        return $this->filterValueMatches($entity->get($field), $operator, $value);
    }

    private function filterValueMatches(mixed $fieldRaw, SurfaceFilterOperator $operator, mixed $value): bool
    {
        $fieldValue = (string) $fieldRaw;
        $filterValue = is_array($value) ? '' : (string) $value;
        $inValues = is_array($value) ? array_map('strval', $value) : explode(',', $filterValue);

        return match ($operator) {
            SurfaceFilterOperator::EQUALS => $fieldValue === $filterValue,
            SurfaceFilterOperator::NOT_EQUALS => $fieldValue !== $filterValue,
            SurfaceFilterOperator::IN => in_array($fieldValue, $inValues, true),
            SurfaceFilterOperator::CONTAINS => mb_stripos($fieldValue, $filterValue) !== false,
            SurfaceFilterOperator::STARTS_WITH => mb_stripos($fieldValue, $filterValue) === 0,
            SurfaceFilterOperator::GT => $this->compareOrderedFilterValues($fieldValue, $filterValue) > 0,
            SurfaceFilterOperator::LT => $this->compareOrderedFilterValues($fieldValue, $filterValue) < 0,
            SurfaceFilterOperator::GTE => $this->compareOrderedFilterValues($fieldValue, $filterValue) >= 0,
            SurfaceFilterOperator::LTE => $this->compareOrderedFilterValues($fieldValue, $filterValue) <= 0,
        };
    }

    /**
     * Compare two values for GT/LT/GTE/LTE filters.
     *
     * When both sides are numeric strings, compare as floats so "10" > "2".
     * Otherwise compare as strings so non-numeric values do not silently become 0.0.
     */
    private function compareOrderedFilterValues(string $fieldValue, string $filterValue): int
    {
        if (is_numeric($fieldValue) && is_numeric($filterValue)) {
            return (float) $fieldValue <=> (float) $filterValue;
        }

        return $fieldValue <=> $filterValue;
    }

    /**
     * Per-entity field-view-access gate used by applyFilter() and the sort
     * comparator (R13 WP1, layer (b)).
     *
     * Complements validateSurfaceQueryFields() (layer (a)): the structural
     * allowlist rejects an entire request for a flatly-Forbidden or unknown
     * field, but some fields are Forbidden only for *some* entities of the
     * type (e.g. classification/clearance-gated fields), which a static
     * allowlist cannot express. This check runs once per entity so those
     * cases are excluded/neutralized individually instead of leaking via a
     * presence/absence or ordering oracle.
     *
     * Fails closed: with no access handler or account the field is treated as
     * Forbidden. In practice list() already short-circuits before filters or
     * sorting run when either is missing, so this branch is a defensive
     * floor, not the primary gate.
     */
    private function isFieldViewForbidden(mixed $entity, string $field): bool
    {
        if ($this->accessHandler === null || $this->currentAccount === null) {
            return true;
        }

        return $this->accessHandler->checkFieldAccess($entity, $field, 'view', $this->currentAccount)->isForbidden();
    }

    /**
     * Structural filter/sort allowlist (R13 WP1, layer (a)), mirroring
     * {@see \Waaseyaa\Api\JsonApiController} `validateQueryFields()` (the REST
     * "audit R2 WP1" gate): reject a filter/sort field that is not a declared
     * field or entity key, is in {@see self::ALWAYS_INTERNAL_FIELDS}, or
     * carries the `internal` field-setting (e.g. `two_factor_secret`). This
     * closes credential fields and unknown field names before any entity is
     * read; per-entity field-view access (isFieldViewForbidden(), layer (b))
     * catches what this static check structurally cannot.
     */
    private function validateSurfaceQueryFields(string $type, SurfaceQuery $query): ?AdminSurfaceResultData
    {
        $fieldDefinitions = $this->entityTypeManager->resolveFieldDefinitions($type);
        $keys = $this->entityTypeManager->getDefinition($type)->getKeys();
        $internalFields = [];
        foreach ($fieldDefinitions as $name => $definition) {
            if ($definition->getSetting('internal') === true) {
                $internalFields[$name] = true;
            }
        }

        // A host may constrain a generic list to declared bundles before it
        // reaches this guard. Include only fields registered for every bundle
        // named by that authoritative constraint; unrelated bundle fields stay
        // unavailable and cannot be selected by a caller-controlled field name.
        $constrainedBundles = $this->constrainedBundles($type, $query);

        $bundleDefinitions = null;
        foreach ($constrainedBundles as $bundle) {
            $resolved = $this->entityTypeManager->resolveFieldDefinitions($type, $bundle);
            foreach ($resolved as $name => $definition) {
                if ($definition->getSetting('internal') === true) {
                    $internalFields[$name] = true;
                }
            }
            $bundleDefinitions = $bundleDefinitions === null
                ? $resolved
                : array_intersect_key($bundleDefinitions, $resolved);
        }
        foreach ($bundleDefinitions ?? [] as $name => $definition) {
            $fieldDefinitions[$name] = $definition;
        }

        /** @var array<string, true> $allowedFields */
        $allowedFields = array_fill_keys(array_keys($fieldDefinitions), true)
            + array_fill_keys(array_values($keys), true);

        $isRejected = static function (string $field) use ($allowedFields, $internalFields): bool {
            if (!isset($allowedFields[$field])) {
                return true;
            }
            if (in_array($field, self::ALWAYS_INTERNAL_FIELDS, true)) {
                return true;
            }
            return isset($internalFields[$field]);
        };

        foreach ($query->filters as $filter) {
            if ($isRejected($filter['field'])) {
                return AdminSurfaceResultData::error(400, 'Invalid filter field', "Cannot filter by field '{$filter['field']}'.");
            }
        }

        if ($query->sortField !== null && $isRejected($query->sortField)) {
            return AdminSurfaceResultData::error(400, 'Invalid sort field', "Cannot sort by field '{$query->sortField}'.");
        }

        return null;
    }

    public function get(string $type, string $id): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        $entity = $this->findByIdOrUuid($type, $id);

        if ($entity === null) {
            return AdminSurfaceResultData::error(404, 'Not found', "Entity '{$type}/{$id}' does not exist.");
        }

        // Fail closed: deny unless an access handler AND account are present and
        // the handler allows the view.
        if (
            $this->accessHandler === null
            || $this->currentAccount === null
            || !$this->accessHandler->check($entity, 'view', $this->currentAccount)->isAllowed()
        ) {
            return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to view this entity.');
        }

        // CW-v1 option-1 (#1920 PR-3, design §4): serve the WORKING COPY to
        // accounts with entity UPDATE access. Documented choice
        // ("unconditional for editors", not a query param like JSON:API's
        // `?workingCopy=1`): the admin SPA's single edit-surface page
        // (`packages/admin/app/pages/[entityType]/[id].vue`) reuses this ONE
        // `get()` call for both its "view" and "edit" client-side sub-modes —
        // there is no per-request signal distinguishing them at the
        // transport layer (`AdminSurfaceTransportAdapter::get()` takes only
        // `type`/`id`) — so gating on update access alone is the minimal
        // coherent shape: the admin surface IS the edit surface, unlike
        // JSON:API's generic-client GET, which has no such context and so
        // requires an explicit opt-in param. An account without update
        // access keeps seeing the published (`find()`) entity — it could
        // never save a draft edit anyway. `loadWorkingCopy()` is mechanically
        // safe for undisciplined entities (=== find()).
        $target = $entity;
        if ($this->accessHandler->check($entity, 'update', $this->currentAccount)->isAllowed()) {
            $target = $this->entityTypeManager->getRepository($type)->loadWorkingCopy((string) $entity->id()) ?? $entity;
        }

        $resource = $this->serializer()->serialize($target, $this->accessHandler, $this->currentAccount);

        return AdminSurfaceResultData::success($this->jsonApiResourceToSurfaceEntity($resource));
    }

    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        if (in_array($type, $this->readOnlyTypes, true) && in_array($action, ['create', 'update', 'delete'], true)) {
            return AdminSurfaceResultData::error(403, 'Read-only entity type', "Type '{$type}' does not allow write actions.");
        }

        if ($action === 'create' && in_array($type, self::MUTABLE_CONFIG_ROW_TYPES, true)) {
            return AdminSurfaceResultData::error(403, 'Create disabled', "Type '{$type}' does not allow create actions from the admin surface.");
        }

        // Check custom actions first
        if (isset($this->actions[$action])) {
            return $this->actions[$action]->handle($type, $payload);
        }

        return match ($action) {
            'schema' => $this->handleSchema($type, $payload),
            'create' => $this->handleCreate($type, $payload),
            'update' => $this->handleUpdate($type, $payload),
            'delete' => $this->handleDelete($type, $payload),
            'generate-slug' => $this->handleGenerateSlug($payload),
            default => AdminSurfaceResultData::error(400, 'Unknown action', "Action '{$action}' is not supported."),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleSchema(string $type, array $payload = []): AdminSurfaceResultData
    {
        $bundle = $this->resolveSchemaBundle($type, $payload);
        if ($this->isCreateBundleSchemaRequest($payload)) {
            if ($this->accessHandler === null
                || $this->currentAccount === null
                || $bundle === null
                || !$this->accessHandler->checkCreateAccess($type, $bundle, $this->currentAccount)->isAllowed()) {
                return AdminSurfaceResultData::error(
                    403,
                    'Access denied',
                    'You do not have permission to create this entity.',
                );
            }
        }

        $presenter = $this->schemaPresenter ?? new SchemaPresenter();
        $controller = new SchemaController(
            $this->entityTypeManager,
            $presenter,
            $this->accessHandler,
            $this->currentAccount,
        );
        $doc = $controller->show($type, $bundle);
        if ($doc->errors !== []) {
            return $this->jsonApiDocumentToSurfaceError($doc);
        }

        $schema = $doc->meta['schema'] ?? null;
        if (!is_array($schema)) {
            return AdminSurfaceResultData::error(500, 'Internal error', 'Schema payload missing.');
        }

        $internalFields = array_values(array_unique(array_merge(
            self::DEFAULT_INTERNAL_FIELDS_BY_TYPE[$type] ?? [],
            $this->internalFieldsByType[$type] ?? [],
        )));
        foreach ($internalFields as $internalField) {
            if ($internalField === '') {
                continue;
            }
            unset($schema['properties'][$internalField]);
            if (isset($schema['required']) && is_array($schema['required'])) {
                $schema['required'] = array_values(array_filter(
                    $schema['required'],
                    static fn(mixed $field): bool => $field !== $internalField,
                ));
            }
        }

        if ($this->workflowBindingResolver !== null) {
            $definition = $this->entityTypeManager->getDefinition($type);
            $bindingBundle = $bundle
                ?? (isset($definition->getKeys()['bundle']) ? '*' : $type);
            $workflow = $this->workflowBindingResolver->resolve($type, $bindingBundle);
            $schema['x-workflow'] = [
                'bound' => $workflow !== null,
                'id' => $workflow?->id(),
            ];

            if ($workflow !== null) {
                unset($schema['properties']['workflow_state'], $schema['properties']['status']);
                if (isset($schema['required']) && is_array($schema['required'])) {
                    $schema['required'] = array_values(array_filter(
                        $schema['required'],
                        static fn(mixed $field): bool => !in_array($field, ['workflow_state', 'status'], true),
                    ));
                }
            }
        }

        return AdminSurfaceResultData::success($schema);
    }

    /** @param array<string, mixed> $payload */
    private function handleGenerateSlug(array $payload): AdminSurfaceResultData
    {
        $value = $payload['value'] ?? null;
        if (!is_string($value)) {
            return AdminSurfaceResultData::error(422, 'Invalid slug source', 'Slug source must be a string.');
        }

        return AdminSurfaceResultData::success(['slug' => SlugGenerator::generate($value)]);
    }

    /** @param array<string, mixed> $payload */
    private function isCreateBundleSchemaRequest(array $payload): bool
    {
        $bundle = $payload['bundle'] ?? null;
        $id = $payload['id'] ?? null;

        return is_string($bundle)
            && $bundle !== ''
            && (!is_string($id) || $id === '');
    }

    /**
     * Resolve the bundle to scope the schema to, so a bundled content type
     * (e.g. a node of bundle "page") exposes its per-bundle fields in the editor
     * form instead of only the shared core fields.
     *
     * Generic: when an entity `id` is given, its stored bundle is authoritative
     * for edit schemas; an accompanying caller-supplied bundle cannot redirect
     * the request to another bundle's fields. Without an id, an explicit
     * `bundle` scopes create discovery after handleSchema() checks create access.
     * Returns null for non-bundled types or when nothing resolves, leaving the
     * base (core-field) schema behaviour unchanged.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveSchemaBundle(string $type, array $payload): ?string
    {
        $id = $payload['id'] ?? null;
        if (is_string($id) && $id !== '') {
            try {
                $entity = $this->findByIdOrUuid($type, $id);
                if ($entity === null) {
                    return null;
                }
                $bundle = $entity->bundle();

                // A bundle equal to the entity type id means "no real bundle"
                // (unbundled types report the type as their bundle).
                return ($bundle !== '' && $bundle !== $type) ? $bundle : null;
            } catch (\Throwable) {
                // Best-effort: fall back to the base schema if the lookup fails.
                return null;
            }
        }

        $bundleHint = $payload['bundle'] ?? null;

        return is_string($bundleHint) && $bundleHint !== '' ? $bundleHint : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleCreate(string $type, array $payload): AdminSurfaceResultData
    {
        // Fail closed: the JSON:API controller only enforces create access when a
        // handler + account are present, so deny here when either is missing.
        if ($this->accessHandler === null || $this->currentAccount === null) {
            return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to create this entity.');
        }

        $attributes = $payload['attributes'] ?? [];
        if (!is_array($attributes)) {
            return AdminSurfaceResultData::error(422, 'Unprocessable', 'Create attributes must be an object.');
        }

        if ($type === 'node') {
            $now = $this->clock->now()->getTimestamp();
            if (!isset($attributes['created']) || $attributes['created'] === '' || $attributes['created'] === 0 || $attributes['created'] === '0') {
                $attributes['created'] = $now;
            }
            // Last-updated is server-owned at creation even if a client echoes
            // a stale/default value from a schema-generated form.
            $attributes['changed'] = $now;
        }

        $presenter = $this->schemaPresenter ?? new SchemaPresenter();
        $availableBundles = $presenter->availableBundles($type);
        if ($availableBundles !== null && $availableBundles !== []) {
            $bundleKey = $this->entityTypeManager->getDefinition($type)->getKeys()['bundle'] ?? null;
            $selectedBundle = $bundleKey !== null ? ($attributes[$bundleKey] ?? null) : null;
            if (!is_string($selectedBundle)
                || $selectedBundle === ''
                || !in_array($selectedBundle, $availableBundles, true)) {
                $bundleKeyLabel = $bundleKey ?? 'bundle';

                return AdminSurfaceResultData::error(
                    422,
                    'Invalid bundle',
                    sprintf(
                        "The '%s' bundle attribute must be one of: %s.",
                        $bundleKeyLabel,
                        implode(', ', $availableBundles),
                    ),
                );
            }
        }

        $api = $this->jsonApi();

        try {
            $doc = $api->store($type, [
                'data' => [
                    'type' => $type,
                    'attributes' => $attributes,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return AdminSurfaceResultData::error(422, 'Unprocessable', $e->getMessage());
        }

        if ($doc->errors !== []) {
            return $this->jsonApiDocumentToSurfaceError($doc);
        }

        if (!$doc->data instanceof JsonApiResource) {
            return AdminSurfaceResultData::error(500, 'Internal error', 'Create returned no resource.');
        }

        return AdminSurfaceResultData::success($this->jsonApiResourceToSurfaceEntity($doc->data));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleUpdate(string $type, array $payload): AdminSurfaceResultData
    {
        $id = $payload['id'] ?? null;
        if ($id === null || $id === '') {
            return AdminSurfaceResultData::error(400, 'Missing ID', 'Payload must include an id field.');
        }

        // Fail closed: the JSON:API controller only enforces update access when a
        // handler + account are present, so deny here when either is missing.
        if ($this->accessHandler === null || $this->currentAccount === null) {
            return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to edit this entity.');
        }

        $api = $this->jsonApi();

        try {
            $doc = $api->update($type, (string) $id, [
                'data' => [
                    'type' => $type,
                    'attributes' => $payload['attributes'] ?? [],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return AdminSurfaceResultData::error(422, 'Unprocessable', $e->getMessage());
        }

        if ($doc->errors !== []) {
            return $this->jsonApiDocumentToSurfaceError($doc);
        }

        if (!$doc->data instanceof JsonApiResource) {
            return AdminSurfaceResultData::error(500, 'Internal error', 'Update returned no resource.');
        }

        return AdminSurfaceResultData::success($this->jsonApiResourceToSurfaceEntity($doc->data));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleDelete(string $type, array $payload): AdminSurfaceResultData
    {
        $id = $payload['id'] ?? null;

        if ($id === null) {
            return AdminSurfaceResultData::error(400, 'Missing ID', 'Payload must include an id field.');
        }

        // The admin SPA sends the JSON:API resource id, which is the UUID for
        // int-keyed content entities. findByIdOrUuid() falls back to a UUID
        // lookup on a non-numeric id, exactly as get()/resolveSchemaBundle() do —
        // without this the delete missed, returned a misleading 404 "Not found",
        // and never reached delete() (D7). Per-entity authorization is still
        // enforced below.
        $entity = $this->findByIdOrUuid($type, (string) $id);

        if ($entity === null) {
            return AdminSurfaceResultData::error(404, 'Not found', "Entity '{$type}/{$id}' does not exist.");
        }

        // Fail closed: deny unless an access handler AND account are present and
        // the handler allows the delete.
        if ($this->accessHandler === null || $this->currentAccount === null) {
            return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to delete this entity.');
        }
        $deleteAccess = $this->accessHandler->check($entity, 'delete', $this->currentAccount);
        if (!$deleteAccess->isAllowed()) {
            $detail = $deleteAccess->isForbidden() && $deleteAccess->reason !== ''
                ? $deleteAccess->reason
                : 'You do not have permission to delete this entity.';

            return AdminSurfaceResultData::error(403, 'Access denied', $detail);
        }

        // C-22 WP3: delete path now goes through the canonical repository.
        $this->entityTypeManager->getRepository($type)->delete($entity);

        return AdminSurfaceResultData::success(['deleted' => true]);
    }

    /**
     * Load an entity by numeric id, falling back to a UUID lookup for
     * non-numeric ids (the admin SPA sends the JSON:API resource id, which is
     * the UUID for int-keyed content entities). C-22 WP3: loadByKey() has no
     * repository equivalent, so the UUID branch is a bounded query + find().
     */
    private function findByIdOrUuid(string $type, string $id): ?\Waaseyaa\Entity\EntityInterface
    {
        $repository = $this->entityTypeManager->getRepository($type);

        $definition = $this->entityTypeManager->getDefinition($type);
        $isConfigEntity = is_a($definition->getClass(), ConfigEntityBase::class, true);
        if (is_numeric($id) || $isConfigEntity) {
            $entity = $repository->find($id);
            if ($entity !== null || $isConfigEntity) {
                return $entity;
            }
        }

        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('uuid', $id)
            ->range(0, 1)
            ->execute();

        return $ids === [] ? null : $repository->find((string) $ids[0]);
    }

    private function jsonApi(): JsonApiController
    {
        return new JsonApiController(
            $this->entityTypeManager,
            $this->serializer(),
            $this->accessHandler,
            $this->currentAccount,
        );
    }

    private static function comparableSortValue(mixed $value): int|float|string
    {
        if ($value instanceof \DateTimeInterface) {
            return (float) $value->format('U.u');
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }

    /** @param list<\Waaseyaa\Entity\EntityInterface> $entities */
    private static function sortEntities(array &$entities, string $field, bool $descending): void
    {
        usort($entities, static function ($a, $b) use ($field, $descending): int {
            $comparison = self::comparableSortValue($a->get($field)) <=> self::comparableSortValue($b->get($field));

            return $descending ? -$comparison : $comparison;
        });
    }

    private function queryUsesMultiBundleScopedField(string $type, SurfaceQuery $query): bool
    {
        $bundles = $this->constrainedBundles($type, $query);
        if (count($bundles) < 2) {
            return false;
        }

        $baseFields = $this->entityTypeManager->resolveFieldDefinitions($type);
        $queriedFields = array_map(static fn(array $filter): string => $filter['field'], $query->filters);
        if ($query->sortField !== null) {
            $queriedFields[] = $query->sortField;
        }
        foreach (array_unique($queriedFields) as $field) {
            if (isset($baseFields[$field])) {
                continue;
            }
            foreach ($bundles as $bundle) {
                if (!isset($this->entityTypeManager->resolveFieldDefinitions($type, $bundle)[$field])) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** @return list<string> */
    private function constrainedBundles(string $type, SurfaceQuery $query): array
    {
        return $query->trustedBundleScope;
    }

    private function serializer(): ResourceSerializer
    {
        return new ResourceSerializer($this->entityTypeManager);
    }

    /**
     * @return array{type: string, id: string, attributes: array<string, mixed>}
     */
    private function jsonApiResourceToSurfaceEntity(JsonApiResource $resource): array
    {
        return [
            'type' => $resource->type,
            'id' => $resource->id,
            'attributes' => $resource->attributes,
        ];
    }

    private function jsonApiDocumentToSurfaceError(\Waaseyaa\Api\JsonApiDocument $doc): AdminSurfaceResultData
    {
        $first = $doc->errors[0] ?? null;
        if (!$first instanceof JsonApiError) {
            return AdminSurfaceResultData::error($doc->statusCode, 'Error', 'Request failed.');
        }

        $status = (int) $first->status;

        return AdminSurfaceResultData::error(
            $status,
            $first->title,
            $first->detail !== '' ? $first->detail : null,
        );
    }
}
