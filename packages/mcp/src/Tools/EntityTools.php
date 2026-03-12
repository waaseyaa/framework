<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Api\JsonApiController;

final class EntityTools extends McpTool
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function getEntity(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? trim($arguments['type']) : '';
        $id = $arguments['id'] ?? null;

        $controller = new JsonApiController(
            entityTypeManager: $this->entityTypeManager,
            serializer: $this->serializer,
            accessHandler: $this->accessHandler,
            account: $this->account,
        );

        return $controller->show($entityType, is_numeric((string) $id) ? (int) $id : (string) $id)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function listEntityTypes(): array
    {
        $types = [];
        foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
            $types[] = [
                'id' => $id,
                'label' => $definition->getLabel(),
                'keys' => $definition->getKeys(),
                'fields' => $definition->getFieldDefinitions(),
            ];
        }

        return ['data' => $types];
    }
}
