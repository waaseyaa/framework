<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\FieldableInterface;

final class DomainValidationListener
{
    private readonly Workflow $workflow;

    /**
     * @param list<string> $workflowBundles
     * @param list<string> $temporalBundles
     * @param list<string> $uniqueTitleBundles
     */
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly array $workflowBundles,
        private readonly array $temporalBundles = [],
        private readonly array $uniqueTitleBundles = [],
        ?Workflow $workflow = null,
    ) {
        $this->workflow = $workflow ?? EditorialWorkflowPreset::create();
    }

    public function __invoke(EntityEvent $event): void
    {
        $entity = $event->entity;
        if ($entity->getEntityTypeId() !== 'node') {
            return;
        }

        $this->validateNode($entity->toArray(), $entity->id(), $entity);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function validateNode(array $values, int|string|null $entityId, mixed $entity): void
    {
        $bundle = (string) ($values['type'] ?? '');
        if (!in_array($bundle, $this->workflowBundles, true)) {
            return;
        }

        $workflowState = EditorialWorkflowPreset::normalizeState(
            workflowState: $values['workflow_state'] ?? null,
            status: $values['status'] ?? 0,
        );
        if (!$this->workflow->hasState($workflowState)) {
            throw new \InvalidArgumentException(sprintf(
                'Validation failed for node bundle "%s": invalid workflow_state "%s".',
                $bundle,
                (string) $workflowState,
            ));
        }
        if ($entity instanceof FieldableInterface) {
            $entity->set('workflow_state', $workflowState);
            $entity->set('status', EditorialWorkflowPreset::statusForState($workflowState));
        }

        if ($entityId !== null) {
            $this->assertWorkflowTransitionAllowed($bundle, (string) $entityId, $workflowState);
        }

        $title = trim((string) ($values['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException(sprintf(
                'Validation failed for node bundle "%s": field "title" is required.',
                $bundle,
            ));
        }

        $body = trim((string) ($values['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException(sprintf(
                'Validation failed for node bundle "%s": field "body" is required.',
                $bundle,
            ));
        }

        if (in_array($bundle, $this->temporalBundles, true)) {
            $start = $this->normalizeTemporal($values['start_date'] ?? null);
            $end = $this->normalizeTemporal($values['end_date'] ?? null);
            if ($start !== null && $end !== null && $start > $end) {
                throw new \InvalidArgumentException(sprintf(
                    'Validation failed for node bundle "%s": "start_date" must be <= "end_date".',
                    $bundle,
                ));
            }
        }

        if (in_array($bundle, $this->uniqueTitleBundles, true)) {
            $this->assertTitleUniqueInBundle($bundle, $title, $entityId);
        }
    }

    private function assertWorkflowTransitionAllowed(string $bundle, string $entityId, string $nextState): void
    {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $existing = $nodeStorage->load(ctype_digit($entityId) ? (int) $entityId : $entityId);
        if ($existing === null) {
            return;
        }

        $previousValues = $existing->toArray();
        $previousState = EditorialWorkflowPreset::normalizeState(
            workflowState: $previousValues['workflow_state'] ?? null,
            status: $previousValues['status'] ?? 0,
        );
        if ($previousState === $nextState) {
            return;
        }

        if (!$this->workflow->isTransitionAllowed($previousState, $nextState)) {
            throw new \InvalidArgumentException(sprintf(
                'Validation failed for node bundle "%s": invalid workflow transition %s -> %s.',
                $bundle,
                $previousState,
                $nextState,
            ));
        }
    }

    private function assertTitleUniqueInBundle(string $bundle, string $title, int|string|null $entityId): void
    {
        $nodeStorage = $this->entityTypeManager->getStorage('node');
        $ids = $nodeStorage->getQuery()
            ->condition('type', $bundle)
            ->accessCheck(false)
            ->execute();
        if ($ids === []) {
            return;
        }

        $normalized = mb_strtolower($title);
        $existing = $nodeStorage->loadMultiple($ids);
        foreach ($existing as $node) {
            if ($entityId !== null && (string) $node->id() === (string) $entityId) {
                continue;
            }

            $existingTitle = mb_strtolower(trim((string) ($node->toArray()['title'] ?? '')));
            if ($existingTitle !== '' && $existingTitle === $normalized) {
                throw new \InvalidArgumentException(
                    sprintf('Validation failed for node bundle "%s": title "%s" must be unique.', $bundle, $title),
                );
            }
        }
    }

    private function normalizeTemporal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (ctype_digit($trimmed)) {
                return (int) $trimmed;
            }
            $timestamp = strtotime($trimmed);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }
}
