<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\Make\AbstractMakeHandler;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Site\Blueprint\Emitter\WorkflowDefinitionEmitter;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowState;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowTransition;

/**
 * Generates a workflow definition/assignment JSON payload that hydrates a
 * real {@see \Waaseyaa\Workflows\Workflow} and resolves through the real
 * {@see \Waaseyaa\Workflows\Binding\WorkflowBindingResolver} (#2848).
 *
 * Builds a {@see BlueprintWorkflow} from CLI input and renders it through
 * {@see WorkflowDefinitionEmitter::toDefinitionArray()} — the same
 * derivation rules (`default_revision := published`, sort-by-id) the
 * blueprint compiler (#2788) uses to emit `<Workflow>WorkflowDefinition`
 * classes — so a manual `scaffold:workflow` invocation and an equivalent
 * blueprint `workflows:` declaration converge on the same states/
 * transitions shape. The pre-#2848 output could not hydrate a real
 * `Workflow` at all (no `label`/`published`/`default_revision`/
 * `initial_state`, and no `entity_type` to form a valid
 * `workflows.assignments` key alongside `--bundle`).
 *
 * Stays stdout-JSON, zero side effects (ADR-025 D-4 `keep` disposition):
 * never writes a file, never throws `GenerationRefusalException`, never
 * touches the staged generation authority — required so this handler stays
 * outside `tests/Architecture/GenerationStagedActivationBoundaryTest.php`'s
 * closed allowlists. Malformed input is refused locally with exit `2`.
 *
 * @api
 */
final class WorkflowScaffoldHandler extends AbstractMakeHandler
{
    public function execute(SymfonyCommandIO $io): int
    {
        $id = trim((string) ($io->option('id') ?? ''));
        $entityType = trim((string) ($io->option('entity-type') ?? ''));
        $bundle = trim((string) ($io->option('bundle') ?? ''));

        if ($id === '' || $entityType === '' || $bundle === '') {
            $io->error('--id, --entity-type, and --bundle are required.');
            return 2;
        }

        if ($this->validateMachineIdentity($io, $id, 'id') === 2) {
            return 2;
        }
        if ($this->validateMachineIdentity($io, $entityType, 'entity-type') === 2) {
            return 2;
        }
        if ($this->validateMachineIdentity($io, $bundle, 'bundle') === 2) {
            return 2;
        }

        /** @var array<mixed> $rawStates */
        $rawStates = (array) ($io->option('state') ?? []);
        $stateIds = $this->parseStateIds($rawStates);
        if ($stateIds === null) {
            $io->error('Invalid --state value: each state id must be a valid machine name.');
            return 2;
        }
        foreach ($stateIds as $stateId) {
            if ($this->validateMachineIdentity($io, $stateId, 'state') === 2) {
                return 2;
            }
        }

        /** @var array<mixed> $rawTransitions */
        $rawTransitions = (array) ($io->option('transition') ?? []);
        $transitions = $this->parseTransitions($rawTransitions);

        if ($transitions === null) {
            $io->error('Invalid --transition format. Use id:from[,from...]:to:permission.');
            return 2;
        }

        if ($stateIds === []) {
            $stateIds = ['draft', 'review', 'published', 'archived'];
        }
        if ($transitions === []) {
            $transitions = [
                ['id' => 'submit_review', 'from' => ['draft'], 'to' => 'review', 'permission' => 'submit article for review'],
                ['id' => 'publish', 'from' => ['review'], 'to' => 'published', 'permission' => 'publish article content'],
                ['id' => 'archive', 'from' => ['published'], 'to' => 'archived', 'permission' => 'archive article content'],
            ];
        }

        $initialStateOption = trim((string) ($io->option('initial-state') ?? ''));
        if ($initialStateOption !== '' && $this->validateMachineIdentity($io, $initialStateOption, 'initial-state') === 2) {
            return 2;
        }
        $initialState = $initialStateOption !== '' ? $initialStateOption : $stateIds[0];

        if (!in_array($initialState, $stateIds, true)) {
            $io->error(sprintf('--initial-state "%s" is not one of the declared --state values.', $initialState));
            return 2;
        }

        $states = [];
        foreach (array_unique($stateIds) as $stateId) {
            $states[$stateId] = new BlueprintWorkflowState($stateId, self::titleCase($stateId), $stateId === 'published');
        }

        $transitionObjects = [];
        foreach ($transitions as $transition) {
            if ($this->validateMachineIdentity($io, $transition['id'], 'transition id') === 2) {
                return 2;
            }
            if ($this->validateMachineIdentity($io, $transition['to'], 'transition to-state') === 2) {
                return 2;
            }
            foreach ($transition['from'] as $fromStateId) {
                if ($this->validateMachineIdentity($io, $fromStateId, 'transition from-state') === 2) {
                    return 2;
                }
            }
            if (isset($transitionObjects[$transition['id']])) {
                $io->error(sprintf('Duplicate transition id "%s" is ambiguous.', $transition['id']));
                return 2;
            }
            foreach ($transition['from'] as $fromStateId) {
                if (!isset($states[$fromStateId])) {
                    $io->error(sprintf('Transition "%s" references undeclared state "%s" in --transition "from".', $transition['id'], $fromStateId));
                    return 2;
                }
            }
            if (!isset($states[$transition['to']])) {
                $io->error(sprintf('Transition "%s" references undeclared state "%s" in --transition "to".', $transition['id'], $transition['to']));
                return 2;
            }
            $transitionObjects[$transition['id']] = new BlueprintWorkflowTransition(
                $transition['id'],
                self::titleCase($transition['id']),
                $transition['from'],
                $transition['to'],
                $transition['permission'],
            );
        }

        $workflow = new BlueprintWorkflow(
            id: $id,
            label: self::titleCase($id),
            initialState: $initialState,
            states: $states,
            transitions: $transitionObjects,
            bindings: [],
        );

        $payload = [
            'workflow' => new WorkflowDefinitionEmitter()->toDefinitionArray($workflow),
            'assignment' => ["{$entityType}.{$bundle}" => $id],
        ];

        $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return 0;
    }

    private function validateMachineIdentity(SymfonyCommandIO $io, string $value, string $what): int
    {
        try {
            $this->validateMachineName($value, $what);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return 2;
        }

        return 0;
    }

    /**
     * @param array<mixed> $rawStates
     * @return list<string>|null null when a non-string state value was supplied
     */
    private function parseStateIds(array $rawStates): ?array
    {
        $states = [];
        foreach ($rawStates as $state) {
            if (!is_string($state)) {
                return null;
            }
            $normalized = strtolower(trim($state));
            if ($normalized !== '') {
                $states[] = $normalized;
            }
        }

        return $states;
    }

    /**
     * @param array<mixed> $rawTransitions
     * @return list<array{id: string, from: list<string>, to: string, permission: string}>|null
     */
    private function parseTransitions(array $rawTransitions): ?array
    {
        $transitions = [];
        foreach ($rawTransitions as $raw) {
            if (!is_string($raw)) {
                return null;
            }
            $parts = explode(':', $raw);
            if (count($parts) !== 4) {
                return null;
            }
            [$tid, $fromRaw, $to, $permission] = array_map(static fn(string $v): string => trim($v), $parts);
            if ($tid === '' || $fromRaw === '' || $to === '' || $permission === '') {
                return null;
            }
            $from = array_values(array_filter(array_map(
                static fn(string $v): string => trim($v),
                explode(',', $fromRaw),
            ), static fn(string $v): bool => $v !== ''));
            if ($from === []) {
                return null;
            }
            $transitions[] = [
                'id' => $tid,
                'from' => $from,
                'to' => $to,
                'permission' => $permission,
            ];
        }

        return $transitions;
    }

    private static function titleCase(string $id): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $id));
    }
}
