<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;

final class EntityTypeLifecycleRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityTypeLifecycleManager $lifecycleManager,
    ) {}

    public function supports(Request $request): bool
    {
        $controller = $request->attributes->get('_controller', '');
        return $controller === 'entity_types' || str_starts_with($controller, 'entity_type.');
    }

    public function handle(Request $request): Response
    {
        $controller = $request->attributes->get('_controller', '');
        $ctx = WaaseyaaContext::fromRequest($request);
        $params = $request->attributes->all();

        return match ($controller) {
            'entity_types' => $this->listTypes(),
            'entity_type.disable' => $this->disableType($params, $ctx),
            'entity_type.enable' => $this->enableType($params, $ctx),
            default => $this->jsonApiResponse(404, [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "Unknown lifecycle action: $controller"]],
            ]),
        };
    }

    private function listTypes(): Response
    {
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
        $types = [];
        foreach ($this->entityTypeManager->getDefinitions() as $id => $def) {
            $types[] = [
                'id' => $id,
                'label' => $def->getLabel(),
                'keys' => $def->getKeys(),
                'translatable' => $def->isTranslatable(),
                'revisionable' => $def->isRevisionable(),
                'group' => $def->getGroup(),
                'disabled' => in_array($id, $disabledIds, true),
            ];
        }
        return $this->jsonApiResponse(200, ['data' => $types]);
    }

    /** @param array<string, mixed> $params */
    private function disableType(array $params, WaaseyaaContext $ctx): Response
    {
        $rawTypeId = (string) ($params['entity_type'] ?? '');
        $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
        $typeId = $normalizer->normalize($rawTypeId);
        $force = filter_var($ctx->query['force'] ?? false, FILTER_VALIDATE_BOOL);

        if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
            return $this->jsonApiResponse(404, [
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId)]],
            ]);
        }
        if ($this->lifecycleManager->isDisabled($typeId)) {
            return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
        }

        $definitions = array_keys($this->entityTypeManager->getDefinitions());
        $disabledIds = $this->lifecycleManager->getDisabledTypeIds();
        $enabledCount = count(array_filter($definitions, fn(string $id) => !in_array($id, $disabledIds, true)));

        if ($enabledCount <= 1 && !$force) {
            return $this->jsonApiResponse(409, [
                'errors' => [['status' => '409', 'title' => 'Conflict', 'detail' => 'Cannot disable the last enabled content type. Enable another type first.']],
            ]);
        }

        $this->lifecycleManager->disable($typeId, (string) $ctx->account->id());
        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => true]]);
    }

    /** @param array<string, mixed> $params */
    private function enableType(array $params, WaaseyaaContext $ctx): Response
    {
        $rawTypeId = (string) ($params['entity_type'] ?? '');
        $normalizer = new EntityTypeIdNormalizer($this->entityTypeManager);
        $typeId = $normalizer->normalize($rawTypeId);

        if ($rawTypeId === '' || !$this->entityTypeManager->hasDefinition($typeId)) {
            return $this->jsonApiResponse(404, [
                'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => sprintf('Unknown entity type: "%s".', $rawTypeId)]],
            ]);
        }
        if (!$this->lifecycleManager->isDisabled($typeId)) {
            return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
        }

        $this->lifecycleManager->enable($typeId, (string) $ctx->account->id());
        return $this->jsonApiResponse(200, ['data' => ['id' => $typeId, 'disabled' => false]]);
    }
}
