<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\Api\Controller\SchemaController;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Api\JsonApiResource;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\ConfigEntityBase;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Generic admin surface host that works with any Waaseyaa application.
 *
 * Auto-discovers entity types from EntityTypeManager and provides full
 * CRUD operations. Apps get a working admin SPA without writing a custom
 * host — just install the admin-surface package.
 *
 * For custom behavior, extend this class and override individual methods,
 * or implement AbstractAdminSurfaceHost directly.
 */
class GenericAdminSurfaceHost extends AbstractAdminSurfaceHost
{
    private ?AccountInterface $currentAccount = null;

    /**
     * @param string[] $readOnlyTypes Entity type IDs that should be read-only in the admin
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
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
        );
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

    public function list(string $type, array $query = []): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        $storage = $this->entityTypeManager->getStorage($type);
        $entities = $storage->loadMultiple();

        if ($this->accessHandler !== null && $this->currentAccount !== null) {
            $entities = array_filter(
                $entities,
                fn($e) => $this->accessHandler->check($e, 'view', $this->currentAccount)->isAllowed(),
            );
        }

        $entities = array_values($entities);
        $total = count($entities);

        $offset = max(0, (int) ($query['page[offset]'] ?? $query['page']['offset'] ?? 0));
        $limit = (int) ($query['page[limit]'] ?? $query['page']['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        $limit = min($limit, 500);

        $serializer = $this->serializer();
        $pageEntities = array_slice($entities, $offset, $limit);

        $surfaceEntities = [];
        foreach ($pageEntities as $entity) {
            $surfaceEntities[] = $this->jsonApiResourceToSurfaceEntity(
                $serializer->serialize($entity, $this->accessHandler, $this->currentAccount),
            );
        }

        return AdminSurfaceResultData::success([
            'entities' => $surfaceEntities,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    public function get(string $type, string $id): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        $storage = $this->entityTypeManager->getStorage($type);
        $entity = $storage->load($id);

        if ($entity === null) {
            return AdminSurfaceResultData::error(404, 'Not found', "Entity '{$type}/{$id}' does not exist.");
        }

        if ($this->accessHandler !== null && $this->currentAccount !== null) {
            if (!$this->accessHandler->check($entity, 'view', $this->currentAccount)->isAllowed()) {
                return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to view this entity.');
            }
        }

        $resource = $this->serializer()->serialize($entity, $this->accessHandler, $this->currentAccount);

        return AdminSurfaceResultData::success($this->jsonApiResourceToSurfaceEntity($resource));
    }

    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
    {
        if (!$this->entityTypeManager->hasDefinition($type)) {
            return AdminSurfaceResultData::error(404, 'Unknown entity type', "Type '{$type}' is not registered.");
        }

        return match ($action) {
            'schema' => $this->handleSchema($type),
            'create' => $this->handleCreate($type, $payload),
            'update' => $this->handleUpdate($type, $payload),
            'delete' => $this->handleDelete($type, $payload),
            default => AdminSurfaceResultData::error(400, 'Unknown action', "Action '{$action}' is not supported."),
        };
    }

    private function handleSchema(string $type): AdminSurfaceResultData
    {
        $presenter = $this->schemaPresenter ?? new SchemaPresenter();
        $controller = new SchemaController(
            $this->entityTypeManager,
            $presenter,
            $this->accessHandler,
            $this->currentAccount,
        );
        $doc = $controller->show($type);
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
     * @param array<string, mixed> $payload
     */
    private function handleCreate(string $type, array $payload): AdminSurfaceResultData
    {
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

        $storage = $this->entityTypeManager->getStorage($type);
        $entity = $storage->load($id);

        if ($entity === null) {
            return AdminSurfaceResultData::error(404, 'Not found', "Entity '{$type}/{$id}' does not exist.");
        }

        if ($this->accessHandler !== null && $this->currentAccount !== null) {
            if (!$this->accessHandler->check($entity, 'delete', $this->currentAccount)->isAllowed()) {
                return AdminSurfaceResultData::error(403, 'Access denied', 'You do not have permission to delete this entity.');
            }
        }

        $storage->delete([$entity]);

        return AdminSurfaceResultData::success(['deleted' => true]);
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
