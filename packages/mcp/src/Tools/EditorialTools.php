<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tools;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Workflows\EditorialTransitionAccessResolver;
use Waaseyaa\Workflows\EditorialWorkflowService;
use Waaseyaa\Workflows\EditorialWorkflowStateMachine;

final class EditorialTools extends McpTool
{
    public function __construct(
        EntityTypeManagerInterface $entityTypeManager,
        ResourceSerializer $serializer,
        EntityAccessHandler $accessHandler,
        AccountInterface $account,
        private readonly EditorialWorkflowStateMachine $editorialStateMachine,
        private readonly EditorialTransitionAccessResolver $editorialTransitionResolver,
    ) {
        parent::__construct($entityTypeManager, $serializer, $accessHandler, $account);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function transition(array $arguments): array
    {
        $targetState = $this->requiredStateArgument($arguments, 'to_state');
        $resolved = $this->loadEditorialNode($arguments);
        $validation = $this->editorialValidationResult($resolved['entity'], $resolved['bundle'], $targetState);
        if (!$validation['is_valid']) {
            throw new \RuntimeException($validation['violations'][0] ?? 'Editorial transition validation failed.');
        }

        $service = $this->editorialWorkflowServiceForBundle($resolved['bundle']);
        $service->transitionNode($resolved['entity'], $targetState, $this->account);
        $resolved['storage']->save($resolved['entity']);

        return [
            'data' => $this->editorialNodeSnapshot(
                $resolved['entity'],
                $resolved['bundle'],
                $service->getAvailableTransitionMetadata($resolved['entity']),
            ),
            'meta' => [
                'tool' => 'editorial_transition',
                'requested_state' => $targetState,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function validate(array $arguments): array
    {
        $resolved = $this->loadEditorialNode($arguments);
        $requestedState = null;
        if (array_key_exists('to_state', $arguments)) {
            $requestedState = $this->requiredStateArgument($arguments, 'to_state');
        }

        $validation = $this->editorialValidationResult($resolved['entity'], $resolved['bundle'], $requestedState);
        $service = $this->editorialWorkflowServiceForBundle($resolved['bundle']);

        return [
            'data' => $this->editorialNodeSnapshot(
                $resolved['entity'],
                $resolved['bundle'],
                $service->getAvailableTransitionMetadata($resolved['entity']),
            ) + [
                'requested_state' => $requestedState,
                'is_valid' => $validation['is_valid'],
                'violations' => $validation['violations'],
            ],
            'meta' => [
                'tool' => 'editorial_validate',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function publish(array $arguments): array
    {
        $arguments['to_state'] = EditorialWorkflowStateMachine::STATE_PUBLISHED;

        return $this->transition($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function archive(array $arguments): array
    {
        $arguments['to_state'] = EditorialWorkflowStateMachine::STATE_ARCHIVED;

        return $this->transition($arguments);
    }

    private function requiredStateArgument(array $arguments, string $name): string
    {
        $state = is_string($arguments[$name] ?? null) ? strtolower(trim($arguments[$name])) : '';
        if ($state === '') {
            throw new \InvalidArgumentException(sprintf('Editorial tool requires non-empty "%s".', $name));
        }
        if (!$this->editorialStateMachine->isKnownState($state)) {
            throw new \InvalidArgumentException(sprintf('Unknown editorial workflow state: "%s".', $state));
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{
     *   entity: EntityInterface&FieldableInterface,
     *   storage: \Waaseyaa\Entity\Storage\EntityStorageInterface,
     *   bundle: string
     * }
     */
    private function loadEditorialNode(array $arguments): array
    {
        $entityType = is_string($arguments['type'] ?? null) ? strtolower(trim($arguments['type'])) : '';
        if ($entityType === '') {
            throw new \InvalidArgumentException('Editorial tools require non-empty "type".');
        }
        if ($entityType !== 'node') {
            throw new \InvalidArgumentException('Editorial tools only support "node" entities.');
        }
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            throw new \InvalidArgumentException(sprintf('Unknown entity type: "%s".', $entityType));
        }

        $idRaw = $arguments['id'] ?? null;
        if (!is_scalar($idRaw) || trim((string) $idRaw) === '') {
            throw new \InvalidArgumentException('Editorial tools require non-empty "id".');
        }
        $resolvedId = ctype_digit((string) $idRaw) ? (int) $idRaw : (string) $idRaw;

        $storage = $this->entityTypeManager->getStorage($entityType);
        $entity = $storage->load($resolvedId);
        if ($entity === null) {
            throw new \InvalidArgumentException(sprintf('Entity not found: %s:%s', $entityType, (string) $idRaw));
        }
        if (!$entity instanceof FieldableInterface) {
            throw new \RuntimeException(sprintf('Entity %s:%s is not fieldable.', $entityType, (string) $idRaw));
        }

        $bundle = strtolower(trim((string) ($entity->bundle() !== '' ? $entity->bundle() : $entity->get('type'))));
        if ($bundle === '') {
            throw new \RuntimeException('Editorial workflow requires a non-empty node bundle.');
        }

        return [
            'entity' => $entity,
            'storage' => $storage,
            'bundle' => $bundle,
        ];
    }

    /**
     * @param EntityInterface&FieldableInterface $entity
     * @return array{is_valid: bool, violations: list<string>}
     */
    private function editorialValidationResult(FieldableInterface $entity, string $bundle, ?string $targetState): array
    {
        $violations = [];

        $updateAccess = $this->accessHandler->check($entity, 'update', $this->account);
        if (!$updateAccess->isAllowed()) {
            $violations[] = $updateAccess->reason !== ''
                ? $updateAccess->reason
                : 'Update access denied for editorial operation.';
        }

        $currentState = $this->editorialStateMachine->normalizeState(
            workflowState: $entity->get('workflow_state'),
            status: $entity->get('status'),
        );
        if (!$this->editorialStateMachine->isKnownState($currentState)) {
            $violations[] = sprintf('Unknown current workflow state: "%s".', $currentState);
        }

        if ($targetState !== null) {
            $transitionAccess = $this->editorialTransitionResolver->canTransition($bundle, $currentState, $targetState, $this->account);
            if (!$transitionAccess->isAllowed()) {
                $violations[] = $transitionAccess->reason !== ''
                    ? $transitionAccess->reason
                    : sprintf('Workflow transition "%s" -> "%s" is not authorized.', $currentState, $targetState);
            }
        }

        return [
            'is_valid' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @param EntityInterface&FieldableInterface $entity
     * @param list<array{id: string, label: string, from: list<string>, to: string, required_permission: string}> $availableTransitions
     * @return array<string, mixed>
     */
    private function editorialNodeSnapshot(FieldableInterface $entity, string $bundle, array $availableTransitions): array
    {
        return [
            'type' => $entity->getEntityTypeId(),
            'id' => (string) $entity->id(),
            'bundle' => $bundle,
            'workflow_state' => $this->editorialStateMachine->normalizeState(
                workflowState: $entity->get('workflow_state'),
                status: $entity->get('status'),
            ),
            'status' => (int) ($entity->get('status') ?? 0),
            'workflow_last_transition' => $entity->get('workflow_last_transition'),
            'available_transitions' => $availableTransitions,
        ];
    }

    private function editorialWorkflowServiceForBundle(string $bundle): EditorialWorkflowService
    {
        return new EditorialWorkflowService(
            coreBundles: [$bundle],
            stateMachine: $this->editorialStateMachine,
            transitionAccessResolver: $this->editorialTransitionResolver,
        );
    }
}
