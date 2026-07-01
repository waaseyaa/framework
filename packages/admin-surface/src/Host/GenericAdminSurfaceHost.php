<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
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
use Waaseyaa\Entity\EntityTypeManagerInterface;

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
    private ?AccountInterface $currentAccount = null;

    /** @var array<string, SurfaceActionHandlerInterface> */
    protected array $actions = [];

    /**
     * @param string[] $readOnlyTypes Entity type IDs that should be read-only in the admin
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly ?SchemaPresenter $schemaPresenter = null,
        private readonly string $tenantId = 'default',
        private readonly string $tenantName = 'Waaseyaa',
        private readonly string $adminPermission = 'administer content',
        private readonly array $readOnlyTypes = [],
    ) {}

    public function resolveSession(Request $request): ?AdminSurfaceSessionData
    {
        $account = $request->attributes->get('_account');

        if (!$account instanceof AccountInterface) {
            return null;
        }

        if (!$account->hasPermission($this->adminPermission)) {
            return null;
        }

        $this->currentAccount = $account;

        return new AdminSurfaceSessionData(
            accountId: (string) $account->id(),
            accountName: 'Admin',
            roles: $account->getRoles(),
            policies: [],
            tenantId: $this->tenantId,
            tenantName: $this->tenantName,
            ui: $this->buildAdminUi($account),
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

            foreach ($definition->getFieldDefinitions() as $name => $fieldDef) {
                $entity->field(
                    $name,
                    $fieldDef['label'] ?? $name,
                    $fieldDef['type'] ?? 'string',
                );
            }

            $isConfig = is_subclass_of($definition->getClass(), ConfigEntityBase::class);
            $isReadOnly = $isConfig || in_array($definition->id(), $this->readOnlyTypes, true);

            if ($isReadOnly) {
                $entity->capabilities([
                    'create' => false,
                    'update' => false,
                    'delete' => false,
                ]);
            } else {
                $entity->action('delete', 'Delete')
                    ->confirm('Are you sure you want to delete this item?')
                    ->dangerous();
            }
        }

        return $catalog;
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

        // C-22 WP3: read path now goes through the canonical repository.
        // findBy([]) is the "load all" equivalent of loadMultiple() with no ids.
        $entities = array_filter(
            $this->entityTypeManager->getRepository($type)->findBy([]),
            fn($e) => $this->accessHandler->check($e, 'view', $this->currentAccount)->isAllowed(),
        );

        // Apply SurfaceQuery filters
        foreach ($query->filters as $filter) {
            $entities = array_filter(
                $entities,
                fn($e) => $this->applyFilter($e, $filter['field'], $filter['operator'], $filter['value']),
            );
        }

        $entities = array_values($entities);

        // Apply sorting
        if ($query->sortField !== null) {
            $field = $query->sortField;
            $desc = $query->sortDirection === 'DESC';
            usort($entities, static function ($a, $b) use ($field, $desc): int {
                $aVal = (string) $a->get($field);
                $bVal = (string) $b->get($field);
                $cmp = $aVal <=> $bVal;

                return $desc ? -$cmp : $cmp;
            });
        }

        $total = count($entities);

        $serializer = $this->serializer();
        $pageEntities = array_slice($entities, $query->offset, $query->limit);

        $surfaceEntities = [];
        foreach ($pageEntities as $entity) {
            $surfaceEntities[] = $this->jsonApiResourceToSurfaceEntity(
                $serializer->serialize($entity, $this->accessHandler, $this->currentAccount),
            );
        }

        return AdminSurfaceResultData::success([
            'entities' => $surfaceEntities,
            'total' => $total,
            'offset' => $query->offset,
            'limit' => $query->limit,
        ]);
    }

    private function applyFilter(mixed $entity, string $field, SurfaceFilterOperator $operator, mixed $value): bool
    {
        $fieldValue = (string) $entity->get($field);
        $filterValue = (string) $value;

        return match ($operator) {
            SurfaceFilterOperator::EQUALS => $fieldValue === $filterValue,
            SurfaceFilterOperator::NOT_EQUALS => $fieldValue !== $filterValue,
            SurfaceFilterOperator::IN => in_array($fieldValue, explode(',', $filterValue), true),
            SurfaceFilterOperator::CONTAINS => mb_stripos($fieldValue, $filterValue) !== false,
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

        $resource = $this->serializer()->serialize($entity, $this->accessHandler, $this->currentAccount);

        return AdminSurfaceResultData::success($this->jsonApiResourceToSurfaceEntity($resource));
    }

    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
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
            default => AdminSurfaceResultData::error(400, 'Unknown action', "Action '{$action}' is not supported."),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleSchema(string $type, array $payload = []): AdminSurfaceResultData
    {
        $presenter = $this->schemaPresenter ?? new SchemaPresenter();
        $controller = new SchemaController(
            $this->entityTypeManager,
            $presenter,
            $this->accessHandler,
            $this->currentAccount,
        );
        $doc = $controller->show($type, $this->resolveSchemaBundle($type, $payload));
        if ($doc->errors !== []) {
            return $this->jsonApiDocumentToSurfaceError($doc);
        }

        $schema = $doc->meta['schema'] ?? null;
        if (!is_array($schema)) {
            return AdminSurfaceResultData::error(500, 'Internal error', 'Schema payload missing.');
        }

        return AdminSurfaceResultData::success($schema);
    }

    /**
     * Resolve the bundle to scope the schema to, so a bundled content type
     * (e.g. a node of bundle "page") exposes its per-bundle fields in the editor
     * form instead of only the shared core fields.
     *
     * Generic: an explicit `bundle` in the payload wins (used for create forms);
     * otherwise, when an entity `id` is given, the bundle is read from that
     * entity, so the client never needs to know which attribute is the bundle
     * key. Returns null for non-bundled types or when nothing resolves, leaving
     * the base (core-field) schema behaviour unchanged.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveSchemaBundle(string $type, array $payload): ?string
    {
        $bundleHint = $payload['bundle'] ?? null;
        if (is_string($bundleHint) && $bundleHint !== '') {
            return $bundleHint;
        }

        $id = $payload['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

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

        $api = $this->jsonApi();

        try {
            $doc = $api->store($type, [
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
        if (
            $this->accessHandler === null
            || $this->currentAccount === null
            || !$this->accessHandler->check($entity, 'delete', $this->currentAccount)->isAllowed()
        ) {
            return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to delete this entity.');
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

        if (is_numeric($id)) {
            $entity = $repository->find($id);
            if ($entity !== null) {
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
