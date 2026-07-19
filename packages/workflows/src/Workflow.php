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
    private array $stateDefinitions = [];

    /**
     * Workflow transitions keyed by transition ID.
     *
     * @var array<string, WorkflowTransition>
     */
    private array $transitionDefinitions = [];

    /**
     * Explicit `initial_state` from config, or null to fall back to the
     * first-declared state (see {@see getInitialState()}).
     */
    private ?string $initialState = null;

    private bool $definitionHydrated = false;

    /**
     * @param array<string, mixed> $values Initial entity values. May include
     *   'states' and 'transitions' arrays for hydration from config.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see EntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
    ) {
        $this->stateDefinitions = [];
        $this->transitionDefinitions = [];

        // Extract and hydrate states from values before passing to parent.
        if (isset($values['states']) && \is_array($values['states'])) {
            foreach ($values['states'] as $stateId => $stateData) {
                if ($stateData instanceof WorkflowState) {
                    $this->stateDefinitions[$stateData->id] = $stateData;
                } elseif (\is_array($stateData)) {
                    $this->stateDefinitions[$stateId] = new WorkflowState(
                        id: (string) $stateId,
                        label: (string) ($stateData['label'] ?? $stateId),
                        weight: (int) ($stateData['weight'] ?? 0),
                        metadata: (array) ($stateData['metadata'] ?? []),
                        published: (bool) ($stateData['published'] ?? false),
                        defaultRevision: (bool) ($stateData['default_revision'] ?? false),
                    );
                }
            }
        }

        // Extract and hydrate transitions from values before passing to parent.
        if (isset($values['transitions']) && \is_array($values['transitions'])) {
            foreach ($values['transitions'] as $transitionId => $transitionData) {
                if ($transitionData instanceof WorkflowTransition) {
                    $this->transitionDefinitions[$transitionData->id] = $transitionData;
                } elseif (\is_array($transitionData)) {
                    $this->transitionDefinitions[$transitionId] = new WorkflowTransition(
                        id: (string) $transitionId,
                        label: (string) ($transitionData['label'] ?? $transitionId),
                        from: (array) ($transitionData['from'] ?? []),
                        to: (string) ($transitionData['to'] ?? ''),
                        weight: (int) ($transitionData['weight'] ?? 0),
                        permission: (string) ($transitionData['permission'] ?? ''),
                        groupConstraint: isset($transitionData['group_constraint'])
                            ? (string) $transitionData['group_constraint']
                            : null,
                    );
                }
            }
        }

        if (isset($values['initial_state']) && \is_string($values['initial_state']) && $values['initial_state'] !== '') {
            $this->initialState = $values['initial_state'];
        }

        $entityTypeId = $entityTypeId !== '' ? $entityTypeId : $this->entityTypeId;
        $entityKeys = $entityKeys !== [] ? $entityKeys : $this->entityKeys;

        parent::__construct($values, $entityTypeId, $entityKeys);
        $this->definitionHydrated = true;
    }

    /**
     * Add a state to this workflow.
     */
    public function addState(WorkflowState $state): static
    {
        $this->ensureDefinitionHydrated();
        $this->stateDefinitions[$state->id] = $state;
        $this->syncStatesToValues();

        return $this;
    }

    /**
     * Get a state by its ID.
     */
    public function getState(string $id): ?WorkflowState
    {
        $this->ensureDefinitionHydrated();
        return $this->stateDefinitions[$id] ?? null;
    }

    /**
     * Get all states in this workflow.
     *
     * @return array<string, WorkflowState>
     */
    public function getStates(): array
    {
        $this->ensureDefinitionHydrated();
        return $this->stateDefinitions;
    }

    /**
     * Check whether this workflow has a state with the given ID.
     */
    public function hasState(string $id): bool
    {
        $this->ensureDefinitionHydrated();
        return isset($this->stateDefinitions[$id]);
    }

    /**
     * Remove a state and any transitions that reference it.
     */
    public function removeState(string $id): static
    {
        $this->ensureDefinitionHydrated();
        unset($this->stateDefinitions[$id]);

        // Remove any transitions that reference the removed state.
        foreach ($this->transitionDefinitions as $transitionId => $transition) {
            // Remove if the target state matches.
            if ($transition->to === $id) {
                unset($this->transitionDefinitions[$transitionId]);
                continue;
            }

            // Filter the removed state from the "from" list.
            $filteredFrom = array_values(array_filter(
                $transition->from,
                static fn(string $stateId): bool => $stateId !== $id,
            ));

            // If no source states remain, remove the entire transition.
            if ($filteredFrom === []) {
                unset($this->transitionDefinitions[$transitionId]);
                continue;
            }

            // If source states changed, rebuild the transition. Adversarial
            // review fix (#1920, WP-3): the rebuild must preserve
            // 'permission' and 'group_constraint' — a fail-open silent drop
            // of either is exactly the misconfiguration the fail-closed
            // design invariant exists to prevent.
            if (\count($filteredFrom) !== \count($transition->from)) {
                $this->transitionDefinitions[$transitionId] = new WorkflowTransition(
                    id: $transition->id,
                    label: $transition->label,
                    from: $filteredFrom,
                    to: $transition->to,
                    weight: $transition->weight,
                    permission: $transition->permission,
                    groupConstraint: $transition->groupConstraint,
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
        $this->ensureDefinitionHydrated();
        $this->transitionDefinitions[$transition->id] = $transition;
        $this->syncTransitionsToValues();

        return $this;
    }

    /**
     * Get a transition by its ID.
     */
    public function getTransition(string $id): ?WorkflowTransition
    {
        $this->ensureDefinitionHydrated();
        return $this->transitionDefinitions[$id] ?? null;
    }

    /**
     * Get all transitions in this workflow.
     *
     * @return array<string, WorkflowTransition>
     */
    public function getTransitions(): array
    {
        $this->ensureDefinitionHydrated();
        return $this->transitionDefinitions;
    }

    /**
     * Check whether this workflow has a transition with the given ID.
     */
    public function hasTransition(string $id): bool
    {
        $this->ensureDefinitionHydrated();
        return isset($this->transitionDefinitions[$id]);
    }

    /**
     * Remove a transition by its ID.
     */
    public function removeTransition(string $id): static
    {
        $this->ensureDefinitionHydrated();
        unset($this->transitionDefinitions[$id]);
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
        $this->ensureDefinitionHydrated();
        $valid = [];

        foreach ($this->transitionDefinitions as $id => $transition) {
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
        $this->ensureDefinitionHydrated();
        foreach ($this->transitionDefinitions as $transition) {
            if ($transition->to === $toStateId && \in_array($fromStateId, $transition->from, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The state new content of this workflow starts in.
     *
     * Explicit `initial_state` config wins; otherwise falls back to the
     * first-declared state (insertion order), matching Task 1.2's contract.
     * Returns '' only when the workflow has no states at all.
     */
    public function getInitialState(): string
    {
        $this->ensureDefinitionHydrated();
        if ($this->initialState !== null) {
            return $this->initialState;
        }

        $firstStateId = \array_key_first($this->stateDefinitions);

        return $firstStateId ?? '';
    }

    /**
     * The permission string required to fire a transition: the transition's
     * own explicit `permission` if set, otherwise the derived
     * `use {workflow_id} transition {transition_id}` name (CW-v1 WP-1).
     */
    public function permissionFor(WorkflowTransition $transition): string
    {
        return $transition->permission !== ''
            ? $transition->permission
            : \sprintf('use %s transition %s', (string) $this->id(), $transition->id);
    }

    private function syncStatesToValues(): void
    {
        $states = [];
        foreach ($this->stateDefinitions as $state) {
            $entry = [
                'label' => $state->label,
                'weight' => $state->weight,
                'published' => $state->published,
                'default_revision' => $state->defaultRevision,
            ];
            if ($state->metadata !== []) {
                $entry['metadata'] = $state->metadata;
            }
            $states[$state->id] = $entry;
        }
        $this->set('states', $states);
    }

    private function syncTransitionsToValues(): void
    {
        $transitions = [];
        foreach ($this->transitionDefinitions as $transition) {
            $entry = [
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'weight' => $transition->weight,
            ];
            if ($transition->permission !== '') {
                $entry['permission'] = $transition->permission;
            }
            if ($transition->groupConstraint !== null) {
                $entry['group_constraint'] = $transition->groupConstraint;
            }
            $transitions[$transition->id] = $entry;
        }
        $this->set('transitions', $transitions);
    }

    /**
     * Serialize the workflow to a config-exportable array.
     *
     * States and transitions are converted from objects to plain arrays
     * suitable for YAML serialization.
     */
    public function toConfig(): array
    {
        $this->ensureDefinitionHydrated();
        $config = parent::toConfig();

        // Serialize states to plain arrays.
        $states = [];
        foreach ($this->stateDefinitions as $state) {
            $entry = [
                'label' => $state->label,
                'weight' => $state->weight,
                'published' => $state->published,
                'default_revision' => $state->defaultRevision,
            ];
            if ($state->metadata !== []) {
                $entry['metadata'] = $state->metadata;
            }
            $states[$state->id] = $entry;
        }
        $config['states'] = $states;

        // Serialize transitions to plain arrays.
        $transitions = [];
        foreach ($this->transitionDefinitions as $transition) {
            $entry = [
                'label' => $transition->label,
                'from' => $transition->from,
                'to' => $transition->to,
                'weight' => $transition->weight,
            ];
            if ($transition->permission !== '') {
                $entry['permission'] = $transition->permission;
            }
            if ($transition->groupConstraint !== null) {
                $entry['group_constraint'] = $transition->groupConstraint;
            }
            $transitions[$transition->id] = $entry;
        }
        $config['transitions'] = $transitions;

        if ($this->initialState !== null) {
            $config['initial_state'] = $this->initialState;
        }

        return $config;
    }

    /** Rebuild constructor-derived value objects after sealed storage hydration. */
    private function ensureDefinitionHydrated(): void
    {
        if ($this->definitionHydrated) {
            return;
        }

        // Sealed storage hydration bypasses constructors. Rebuild only this
        // public config entity's exact definition fields from its retained
        // values; no general value bag escapes this class.
        $obtain = \Closure::bind(
            static fn(\Waaseyaa\Entity\EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            \Waaseyaa\Entity\EntityBase::class,
        );
        $values = $obtain($this);
        $states = $values['states'] ?? null;
        $transitions = $values['transitions'] ?? null;
        $initialState = $values['initial_state'] ?? null;
        $hydrated = new self([
            'states' => is_array($states) ? $states : [],
            'transitions' => is_array($transitions) ? $transitions : [],
            'initial_state' => is_string($initialState) ? $initialState : '',
        ]);
        $this->stateDefinitions = $hydrated->stateDefinitions;
        $this->transitionDefinitions = $hydrated->transitionDefinitions;
        $this->initialState = $hydrated->initialState;
        $this->definitionHydrated = true;
    }
}
