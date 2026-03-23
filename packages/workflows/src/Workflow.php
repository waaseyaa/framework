<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Configuration entity representing an editorial workflow.
 *
 * A workflow defines a set of states and the allowed transitions
 * between them. For example, an "Editorial" workflow might define
 * states like "draft", "review", and "published", with transitions
 * such as "submit for review" (draft -> review) and "publish"
 * (review -> published).
 */
final class Workflow extends ConfigEntityBase
{
    protected string $entityTypeId = 'workflow';

    protected array $entityKeys = [
        'id' => 'id',
        'label' => 'label',
    ];

    /**
     * Workflow states keyed by state ID.
     *
     * @var array<string, WorkflowState>
     */
    private array $states = [];

    /**
     * Workflow transitions keyed by transition ID.
     *
     * @var array<string, WorkflowTransition>
     */
    private array $transitions = [];

    /**
     * @param array<string, mixed> $values Initial entity values. May include
     *   'states' and 'transitions' arrays for hydration from config.
     */
    public function __construct(array $values = [])
    {
        // Extract and hydrate states from values before passing to parent.
        if (isset($values['states']) && \is_array($values['states'])) {
            foreach ($values['states'] as $stateId => $stateData) {
                if ($stateData instanceof WorkflowState) {
                    $this->states[$stateData->id] = $stateData;
                } elseif (\is_array($stateData)) {
                    $this->states[$stateId] = new WorkflowState(
                        id: (string) $stateId,
                        label: (string) ($stateData['label'] ?? $stateId),
                        weight: (int) ($stateData['weight'] ?? 0),
                        metadata: (array) ($stateData['metadata'] ?? []),
                    );
                }
            }
        }

        // Extract and hydrate transitions from values before passing to parent.
        if (isset($values['transitions']) && \is_array($values['transitions'])) {
            foreach ($values['transitions'] as $transitionId => $transitionData) {
                if ($transitionData instanceof WorkflowTransition) {
                    $this->transitions[$transitionData->id] = $transitionData;
                } elseif (\is_array($transitionData)) {
                    $this->transitions[$transitionId] = new WorkflowTransition(
                        id: (string) $transitionId,
                        label: (string) ($transitionData['label'] ?? $transitionId),
                        from: (array) ($transitionData['from'] ?? []),
                        to: (string) ($transitionData['to'] ?? ''),
                        weight: (int) ($transitionData['weight'] ?? 0),
                    );
                }
            }
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }

    /**
     * Add a state to this workflow.
     */
    public function addState(WorkflowState $state): static
    {
        $this->states[$state->id] = $state;
        $this->syncStatesToValues();

        return $this;
    }

    /**
     * Get a state by its ID.
     */
    public function getState(string $id): ?WorkflowState
    {
        return $this->states[$id] ?? null;
    }

    /**
     * Get all states in this workflow.
     *
     * @return array<string, WorkflowState>
     */
    public function getStates(): array
    {
        return $this->states;
    }

    /**
     * Check whether this workflow has a state with the given ID.
     */
    public function hasState(string $id): bool
    {
        return isset($this->states[$id]);
    }

    /**
     * Remove a state and any transitions that reference it.
     */
    public function removeState(string $id): static
    {
        unset($this->states[$id]);

        // Remove any transitions that reference the removed state.
        foreach ($this->transitions as $transitionId => $transition) {
            // Remove if the target state matches.
            if ($transition->to === $id) {
                unset($this->transitions[$transitionId]);
                continue;
            }

            // Filter the removed state from the "from" list.
            $filteredFrom = array_values(array_filter(
                $transition->from,
                static fn(string $stateId): bool => $stateId !== $id,
            ));

            // If no source states remain, remove the entire transition.
            if ($filteredFrom === []) {
                unset($this->transitions[$transitionId]);
                continue;
            }

            // If source states changed, rebuild the transition.
            if (\count($filteredFrom) !== \count($transition->from)) {
                $this->transitions[$transitionId] = new WorkflowTransition(
                    id: $transition->id,
                    label: $transition->label,
                    from: $filteredFrom,
                    to: $transition->to,
                    weight: $transition->weight,
                );
            }
        }

        $this->syncStatesToValues();
        $this->syncTransitionsToValues();

        return $this;
    }

    /**
     * Add a transition to this workflow.
     */
    public function addTransition(WorkflowTransition $transition): static
    {
        $this->transitions[$transition->id] = $transition;
        $this->syncTransitionsToValues();

        return $this;
    }

    /**
     * Get a transition by its ID.
     */
    public function getTransition(string $id): ?WorkflowTransition
    {
        return $this->transitions[$id] ?? null;
    }

    /**
     * Get all transitions in this workflow.
     *
     * @return array<string, WorkflowTransition>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * Check whether this workflow has a transition with the given ID.
     */
    public function hasTransition(string $id): bool
    {
        return isset($this->transitions[$id]);
    }

    /**
     * Remove a transition by its ID.
     */
    public function removeTransition(string $id): static
    {
        unset($this->transitions[$id]);
        $this->syncTransitionsToValues();

        return $this;
    }

    /**
     * Get all transitions available from a given state.
     *
     * @return array<string, WorkflowTransition>
     */
    public function getValidTransitions(string $fromStateId): array
    {
        $valid = [];

        foreach ($this->transitions as $id => $transition) {
            if (\in_array($fromStateId, $transition->from, true)) {
                $valid[$id] = $transition;
            }
        }

        return $valid;
    }

    /**
     * Check if a direct transition between two states is allowed.
     */
    public function isTransitionAllowed(string $fromStateId, string $toStateId): bool
    {
        foreach ($this->transitions as $transition) {
            if ($transition->to === $toStateId && \in_array($fromStateId, $transition->from, true)) {
                return true;
            }
        }

        return false;
    }

    private function syncStatesToValues(): void
    {
        $states = [];
        foreach ($this->states as $state) {
            $entry = [
                'label' => $state->label,
                'weight' => $state->weight,
            ];
            if ($state->metadata !== []) {
                $entry['metadata'] = $state->metadata;
            }
            $states[$state->id] = $entry;
        }
        $this->values['states'] = $states;
    }

    private function syncTransitionsToValues(): void
    {
        $transitions = [];
        foreach ($this->transitions as $transition) {
            $transitions[$transition->id] = [
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'weight' => $transition->weight,
            ];
        }
        $this->values['transitions'] = $transitions;
    }

    /**
     * Serialize the workflow to a config-exportable array.
     *
     * States and transitions are converted from objects to plain arrays
     * suitable for YAML serialization.
     */
    public function toConfig(): array
    {
        $config = parent::toConfig();

        // Serialize states to plain arrays.
        $states = [];
        foreach ($this->states as $state) {
            $entry = [
                'label' => $state->label,
                'weight' => $state->weight,
            ];
            if ($state->metadata !== []) {
                $entry['metadata'] = $state->metadata;
            }
            $states[$state->id] = $entry;
        }
        $config['states'] = $states;

        // Serialize transitions to plain arrays.
        $transitions = [];
        foreach ($this->transitions as $transition) {
            $transitions[$transition->id] = [
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'weight' => $transition->weight,
            ];
        }
        $config['transitions'] = $transitions;

        return $config;
    }
}
