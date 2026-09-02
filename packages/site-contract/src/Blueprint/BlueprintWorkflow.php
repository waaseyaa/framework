<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintWorkflow
{
    /**
     * @param array<string, BlueprintWorkflowState> $states
     * @param array<string, BlueprintWorkflowTransition> $transitions
     * @param list<BlueprintWorkflowBinding> $bindings
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $initialState,
        public array $states,
        public array $transitions,
        public array $bindings,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $bindings = $this->bindings;
        usort($bindings, static fn(BlueprintWorkflowBinding $a, BlueprintWorkflowBinding $b): int => $a->entity <=> $b->entity);

        return [
            'id' => $this->id,
            'label' => $this->label,
            'initial_state' => $this->initialState,
            'states' => array_map(static fn(BlueprintWorkflowState $state): array => $state->toArray(), array_values(self::sortedById($this->states))),
            'transitions' => array_map(static fn(BlueprintWorkflowTransition $transition): array => $transition->toArray(), array_values(self::sortedById($this->transitions))),
            'bindings' => array_map(static fn(BlueprintWorkflowBinding $binding): array => $binding->toArray(), $bindings),
        ];
    }

    /**
     * @template T
     * @param array<string, T> $items
     * @return array<string, T>
     */
    private static function sortedById(array $items): array
    {
        ksort($items, SORT_STRING);

        return $items;
    }
}
