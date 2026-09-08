<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\Config\Schema\ConfigSchemaRegistration;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncSerializer;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowBinding;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowState;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowTransition;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsConfig;

/**
 * Emits one `src/Workflow/<PascalCase(workflow.id)>WorkflowDefinition.php`
 * per blueprint workflow, plus one aggregate `config/sync/workflows.
 * assignments.yml` binding every declared `<entity.id>.<entity.id> =>
 * <workflow.id>` pair across every workflow — but ONLY when at least one
 * binding exists anywhere in the blueprint (#2788 review F9): a blueprint
 * that declares workflows with zero bindings emits zero `config/sync/*`
 * artifacts, never an empty `{}` (which would seed a trusted CFG-03 config
 * entry for a binding nobody authored) — #2788, FW-SITE-BLUEPRINT-01E.
 *
 * `DEFINITION` is the exact `Workflow::__construct()` hydration shape
 * ({@see \Waaseyaa\Workflows\Workflow}), matching
 * {@see \Waaseyaa\Workflows\DefaultWorkflows::EDITORIAL} byte-for-byte in
 * structure — `new Workflow(<Generated>WorkflowDefinition::DEFINITION)`
 * constructs a real, valid workflow. A blueprint workflow state has no
 * `default_revision` declaration of its own (unlike `DefaultWorkflows`,
 * which sets it independently of `published`), so this emitter derives
 * `default_revision := published` for every state — the framework's own
 * "published states are what a reader without special access should see"
 * default (`docs/specs/content-workflow.md`).
 *
 * Bundle-less binding: `WorkflowBindingResolver` keys assignments
 * `<entity_type>.<bundle>`, and a blueprint-generated entity is bundle-less
 * (`EntityBase::bundle()` falls back to the entity type id itself when no
 * bundle key is declared) — so every binding row is `<entity.id>.<entity.id>`.
 *
 * Emits nothing when the blueprint declares zero workflows (e.g.
 * `minimal.yaml`).
 *
 * @api
 */
final class WorkflowDefinitionEmitter implements BlueprintArtifactEmitterInterface
{
    private const string ASSIGNMENTS_PATH = 'config/sync/workflows.assignments.yml';

    public function id(): string
    {
        return 'workflow-definition';
    }

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
    {
        if ($blueprint->workflows === []) {
            return new BlueprintEmission([]);
        }

        self::assertNoWorkflowClassNameCollision($blueprint);

        $workflows = array_values($blueprint->workflows);
        usort($workflows, static fn(BlueprintWorkflow $left, BlueprintWorkflow $right): int => strcmp($left->id, $right->id));

        $artifacts = [];
        $bindingRows = [];
        foreach ($workflows as $workflow) {
            $className = self::pascalCase($workflow->id) . 'WorkflowDefinition';
            $artifacts[] = new GeneratedArtifact(
                'src/Workflow/' . $className . '.php',
                $this->renderDefinition($workflow, $className),
            );

            $bindings = $workflow->bindings;
            usort($bindings, static fn(BlueprintWorkflowBinding $left, BlueprintWorkflowBinding $right): int => strcmp($left->entity, $right->entity));
            foreach ($bindings as $binding) {
                $bindingRows["{$binding->entity}.{$binding->entity}"] = $workflow->id;
            }
        }
        ksort($bindingRows, SORT_STRING);

        // #2788 review F9: the design says this artifact is emitted only
        // "when any binding exists" — an empty `{}` seeds a trusted CFG-03
        // config entry for a binding that was never authored, so a later
        // legitimately authored assignment becomes an update instead of a
        // create.
        if ($bindingRows !== []) {
            $artifacts[] = new GeneratedArtifact(self::ASSIGNMENTS_PATH, $this->renderAssignments($bindingRows));
        }
        usort($artifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));

        return new BlueprintEmission($artifacts);
    }

    /**
     * Two workflow ids that PascalCase to the same string would emit the
     * same `src/Workflow/<Class>WorkflowDefinition.php` path twice within
     * this emitter's OWN artifact list — `ApplicationBlueprintCompiler`'s
     * cross-emitter path-uniqueness check does catch that (a bare
     * `\InvalidArgumentException`, not a coded refusal), but this refuses
     * with `GEN006_MALICIOUS_IDENTIFIER` before any artifact is rendered at
     * all, mirroring `ApplicationBlueprintCompiler::checkClassNameCollision()`
     * (#2788 review F6).
     */
    private static function assertNoWorkflowClassNameCollision(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        $seen = [];
        $index = 0;
        foreach ($blueprint->workflows as $workflow) {
            $key = strtolower(self::pascalCase($workflow->id));
            if (isset($seen[$key])) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::MaliciousIdentifier,
                    "Blueprint workflow \"{$workflow->id}\" PascalCases to the same generated class name as workflow \"{$seen[$key]}\".",
                    pointer: "/application_blueprint/workflows/{$index}/id",
                );
            } else {
                $seen[$key] = $workflow->id;
            }
            ++$index;
        }

        if ($violations !== []) {
            throw new GenerationRefusalException(self::class, $violations);
        }
    }

    /**
     * Public convergence entrypoint for `scaffold:workflow` (#2848): returns
     * the plain {@see \Waaseyaa\Workflows\Workflow::__construct()} hydration
     * array for an explicit `BlueprintWorkflow` the caller builds itself,
     * rather than the PHP-source class text {@see renderDefinition()}
     * builds. Both output serializers consume this canonical transformation:
     * PHP rendering only escapes and formats these values, preserving the
     * existing golden bytes. Shared derivation rules are:
     * `default_revision := published`, and states/transitions sorted by id.
     *
     * @return array{id: string, label: string, initial_state: string, states: array<string, array{label: string, published: bool, default_revision: bool}>, transitions: array<string, array{label: string, from: list<string>, to: string, permission: string}>}
     */
    public function toDefinitionArray(BlueprintWorkflow $workflow): array
    {
        $states = array_values($workflow->states);
        usort($states, static fn(BlueprintWorkflowState $left, BlueprintWorkflowState $right): int => strcmp($left->id, $right->id));
        $transitions = array_values($workflow->transitions);
        usort($transitions, static fn(BlueprintWorkflowTransition $left, BlueprintWorkflowTransition $right): int => strcmp($left->id, $right->id));

        $statesArray = [];
        foreach ($states as $state) {
            $statesArray[$state->id] = [
                'label' => $state->label,
                'published' => $state->published,
                'default_revision' => $state->published,
            ];
        }

        $transitionsArray = [];
        foreach ($transitions as $transition) {
            $from = $transition->from;
            sort($from, SORT_STRING);
            $transitionsArray[$transition->id] = [
                'label' => $transition->label,
                'from' => $from,
                'to' => $transition->to,
                'permission' => $transition->permission,
            ];
        }

        return [
            'id' => $workflow->id,
            'label' => $workflow->label,
            'initial_state' => $workflow->initialState,
            'states' => $statesArray,
            'transitions' => $transitionsArray,
        ];
    }

    private function renderDefinition(BlueprintWorkflow $workflow, string $className): string
    {
        $definition = $this->toDefinitionArray($workflow);
        $stateRows = '';
        foreach ($definition['states'] as $stateId => $state) {
            $stateRows .= $this->renderStateRow($stateId, $state);
        }
        $transitionRows = '';
        foreach ($definition['transitions'] as $transitionId => $transition) {
            $transitionRows .= $this->renderTransitionRow($transitionId, $transition);
        }

        $id = self::singleQuoted($definition['id']);
        $label = self::singleQuoted($definition['label']);
        $initialState = self::singleQuoted($definition['initial_state']);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Workflow;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Pass DEFINITION to `new Waaseyaa\\Workflows\\Workflow(...)`.
             */
            final class {$className}
            {
                /** @var array<string, mixed> */
                public const array DEFINITION = [
                    'id' => {$id},
                    'label' => {$label},
                    'initial_state' => {$initialState},
                    'states' => [
            {$stateRows}        ],
                    'transitions' => [
            {$transitionRows}        ],
                ];
            }

            PHP;
    }

    /** @param array{label: string, published: bool, default_revision: bool} $state */
    private function renderStateRow(string $stateId, array $state): string
    {
        $id = self::singleQuoted($stateId);
        $label = self::singleQuoted($state['label']);
        $published = $state['published'] ? 'true' : 'false';
        $defaultRevision = $state['default_revision'] ? 'true' : 'false';

        return "            {$id} => ['label' => {$label}, 'published' => {$published}, 'default_revision' => {$defaultRevision}],\n";
    }

    /** @param array{label: string, from: list<string>, to: string, permission: string} $transition */
    private function renderTransitionRow(string $transitionId, array $transition): string
    {
        $id = self::singleQuoted($transitionId);
        $label = self::singleQuoted($transition['label']);
        $from = $transition['from'];
        $fromArray = '[' . implode(', ', array_map(self::singleQuoted(...), $from)) . ']';
        $to = self::singleQuoted($transition['to']);
        $permission = self::singleQuoted($transition['permission']);

        return <<<PHP
                        {$id} => [
                            'label' => {$label},
                            'from' => {$fromArray},
                            'to' => {$to},
                            'permission' => {$permission},
                        ],

            PHP;
    }

    /**
     * CFG-03 writable sync artifact for {@see WorkflowAssignmentsConfig::CONFIG_NAME}.
     * Schema identity is derived from the real guarded registration; the empty
     * entity-type manager supplies only the registration call shape — emitted
     * binding rows are not validated against installed entity types here.
     *
     * @param array<string, string> $bindingRows "entity.entity" => workflow id, sorted by key, always non-empty (see emit())
     */
    private function renderAssignments(array $bindingRows): string
    {
        $registration = self::workflowAssignmentsSchemaRegistration();
        $file = ConfigSyncFile::writable(
            entityType: 'workflows',
            entityId: 'assignments',
            uuid: ConfigSyncFile::deterministicUuid('workflows', 'assignments'),
            dependencies: [],
            langcode: 'en',
            fields: $bindingRows,
            schemaId: $registration->schemaId,
            schemaVersion: $registration->schemaVersion,
            schemaHash: $registration->canonicalSchemaHash,
            ownerPackage: $registration->ownerPackage,
            ownerConfigContractVersion: $registration->ownerConfigContractVersion,
        );

        return new ConfigSyncSerializer()->toYaml($file);
    }

    /**
     * Guarded {@see WorkflowAssignmentsConfig} registration for CFG-03 identity.
     * The entity-type manager is not consulted for binding admissibility at emit
     * time — only the canonical schema hash and owner contract are needed.
     */
    private static function workflowAssignmentsSchemaRegistration(): ConfigSchemaRegistration
    {
        $registry = new ConfigSchemaRegistry();

        return WorkflowAssignmentsConfig::register(
            $registry,
            new EntityTypeManager(new SymfonyEventDispatcherAdapter()),
        );
    }

    private static function singleQuoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
