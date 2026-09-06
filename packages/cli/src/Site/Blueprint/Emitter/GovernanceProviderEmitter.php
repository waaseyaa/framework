<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintRole;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Emits `src/Provider/ApplicationBlueprintGovernanceServiceProvider.php` and
 * its {@see ComposerProviderRegistration} (#2788, FW-SITE-BLUEPRINT-01E).
 *
 * A SECOND, DISTINCT provider FQCN from `ProviderRegistrationEmitter`'s
 * `App\Provider\ApplicationBlueprintServiceProvider`: `ArtifactPlan` refuses
 * a duplicate registration FQCN at construction
 * (`packages/site-contract/src/Generation/ArtifactPlan.php`), so the two
 * emitters compose additively rather than one editing the other's provider.
 *
 * Implements {@see \Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface}:
 * `roles()` yields one `Waaseyaa\User\Role` per declared blueprint role, its
 * `permissions` list carrying the declared permission id STRINGS verbatim.
 *
 * When the blueprint declares permissions, the provider also implements
 * {@see \Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface}
 * and `permissions()` returns `ApplicationBlueprintPermissions::seed()`
 * (the class `PermissionCatalogueEmitter` emits), so the kernel's shared
 * boot-time catalogue (`PermissionHandler::fromProviders()`) knows every
 * blueprint permission and `RoleRepository::assertPermissionsCatalogued()`
 * accepts every generated role grant — an uncatalogued grant fails boot
 * (#2788 G1 closed).
 *
 * `boot()` seeds every declared workflow as a persisted `workflow` config
 * entity, mirroring `WorkflowServiceProvider::seedDefaultEditorialWorkflow()`
 * /`topUpDefaultEditorialWorkflow()` verbatim (`[S1-DB106]` no-schema-yet
 * return, `enforceIsNew()`, log-and-skip on validation failure, additive
 * machine-name top-up that never touches an already-persisted state or
 * transition) for each `<Workflow>WorkflowDefinition::DEFINITION`.
 *
 * Policies need no provider: `#[PolicyAttribute]` is source-scanned over root
 * PSR-4 prefixes (decision (f)) — a generated `App\Access\<Entity>Policy` is
 * discovered without any registration call.
 *
 * Compile-time refusal (`GEN007_UNSUPPORTED_DECLARATION`, before any artifact
 * is emitted): a role id of `administrator` is refused — that id is `Waaseyaa\
 * User\User::hasPermission()`'s hardcoded bypass-all-permissions role
 * (`ADMINISTRATOR_ROLE`), and a blueprint has no declarative way to express
 * "bypass every permission check", so silently honoring an authored
 * `administrator` role id would grant far more than its declared
 * `permissions` list says.
 *
 * Emits nothing when the blueprint declares zero roles, zero workflows AND
 * zero permissions (e.g. `minimal.yaml`).
 *
 * @api
 */
final class GovernanceProviderEmitter implements BlueprintArtifactEmitterInterface
{
    private const string FQCN = 'App\\Provider\\ApplicationBlueprintGovernanceServiceProvider';
    private const string PATH = 'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php';

    /** The role id `Waaseyaa\User\User::hasPermission()` treats as bypass-all. */
    private const string RESERVED_ADMINISTRATOR_ROLE = 'administrator';

    public function id(): string
    {
        return 'governance-provider';
    }

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
    {
        self::assertNoReservedRoleId($blueprint);

        if ($blueprint->roles === [] && $blueprint->workflows === [] && $blueprint->permissions === []) {
            return new BlueprintEmission([]);
        }

        $roles = array_values($blueprint->roles);
        usort($roles, static fn(BlueprintRole $left, BlueprintRole $right): int => strcmp($left->id, $right->id));

        $workflows = array_values($blueprint->workflows);
        usort($workflows, static fn(BlueprintWorkflow $left, BlueprintWorkflow $right): int => strcmp($left->id, $right->id));

        $content = $this->renderProvider($roles, $workflows, $blueprint->permissions !== []);

        return new BlueprintEmission(
            [new GeneratedArtifact(self::PATH, $content)],
            [new ComposerProviderRegistration(self::FQCN)],
        );
    }

    /** @param list<BlueprintRole> $roles @param list<BlueprintWorkflow> $workflows */
    private function renderProvider(array $roles, array $workflows, bool $hasCatalogue): string
    {
        $roleYields = implode('', array_map($this->renderRoleYield(...), $roles));
        $rolesMethod = $roles === []
            ? "    public function roles(): iterable\n    {\n        return [];\n    }\n"
            : "    public function roles(): iterable\n    {\n{$roleYields}    }\n";

        // #2788 G1 closed: the shared kernel catalogue collects this seed
        // through ProvidesPermissionsInterface, so every generated role grant
        // is catalogued before boot completes.
        $catalogueUse = $hasCatalogue ? "use App\\Access\\ApplicationBlueprintPermissions;\n" : '';
        $catalogueImplements = $hasCatalogue ? ', ProvidesPermissionsInterface' : '';
        $catalogueInterfaceUse = $hasCatalogue ? "use Waaseyaa\\Foundation\\ServiceProvider\\Capability\\ProvidesPermissionsInterface;\n" : '';
        $permissionsMethod = $hasCatalogue
            ? "\n    public function permissions(): array\n    {\n        return ApplicationBlueprintPermissions::seed();\n    }\n"
            : '';

        $workflowUses = implode('', array_map(
            static fn(BlueprintWorkflow $workflow): string => 'use App\\Workflow\\' . self::pascalCase($workflow->id) . "WorkflowDefinition;\n",
            $workflows,
        ));
        $seedCalls = implode('', array_map(
            static fn(BlueprintWorkflow $workflow): string => '        $this->seedWorkflow(' . self::quoted($workflow->id) . ', ' . self::pascalCase($workflow->id) . "WorkflowDefinition::DEFINITION, \$entityTypeManager, \$logger);\n",
            $workflows,
        ));
        $bootMethod = $workflows === []
            ? "    public function boot(): void\n    {\n    }\n"
            : <<<PHP
                    public function boot(): void
                    {
                        \$entityTypeManager = \$this->resolveOptional(\\Waaseyaa\\Entity\\EntityTypeManager::class);
                        if (!\$entityTypeManager instanceof \\Waaseyaa\\Entity\\EntityTypeManagerInterface) {
                            return;
                        }
                        \$logger = \$this->resolveOptional(\\Waaseyaa\\Foundation\\Log\\LoggerInterface::class);
                        \$logger = \$logger instanceof \\Waaseyaa\\Foundation\\Log\\LoggerInterface ? \$logger : new \\Waaseyaa\\Foundation\\Log\\NullLogger();

                {$seedCalls}    }

                PHP;

        $seedHelpers = $workflows === [] ? '' : $this->renderSeedHelpers();

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Provider;

            {$catalogueUse}{$workflowUses}{$catalogueInterfaceUse}use Waaseyaa\\Foundation\\ServiceProvider\\Capability\\ProvidesRolesInterface;
            use Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider;
            use Waaseyaa\\User\\Role;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand.
             */
            final class ApplicationBlueprintGovernanceServiceProvider extends ServiceProvider implements ProvidesRolesInterface{$catalogueImplements}
            {
                public function register(): void
                {
                }

            {$rolesMethod}{$permissionsMethod}
            {$bootMethod}{$seedHelpers}}

            PHP;
    }

    private function renderRoleYield(BlueprintRole $role): string
    {
        $permissions = $role->permissions;
        sort($permissions, SORT_STRING);
        $permissionsArray = '[' . implode(', ', array_map(self::quoted(...), $permissions)) . ']';

        return '        yield new Role(' . self::quoted($role->id) . ', ' . self::quoted($role->label) . ", {$permissionsArray});\n";
    }

    /**
     * Mirrors `WorkflowServiceProvider::seedDefaultEditorialWorkflow()` /
     * `topUpDefaultEditorialWorkflow()` / `addMissingStates()` /
     * `addMissingTransitions()` verbatim, generalized from the one shipped
     * `editorial` workflow to any generated `$id`/`$definition` pair.
     */
    private function renderSeedHelpers(): string
    {
        return <<<'PHP'

                private function seedWorkflow(
                    string $id,
                    array $definition,
                    \Waaseyaa\Entity\EntityTypeManagerInterface $entityTypeManager,
                    \Waaseyaa\Foundation\Log\LoggerInterface $logger,
                ): void {
                    try {
                        $repository = $entityTypeManager->getRepository('workflow');
                    } catch (\RuntimeException $exception) {
                        if (str_contains($exception->getMessage(), '[S1-DB106]')) {
                            return;
                        }
                        throw $exception;
                    }

                    $existing = $repository->find($id);
                    if ($existing instanceof \Waaseyaa\Workflows\Workflow) {
                        $this->topUpWorkflow($existing, $definition, $repository, $logger);

                        return;
                    }

                    $workflow = new \Waaseyaa\Workflows\Workflow($definition);
                    $violations = new \Waaseyaa\Workflows\Validation\WorkflowValidator()->validate($workflow);
                    if ($violations !== []) {
                        $logger->warning('application_blueprint.governance.workflow_seed_invalid', ['workflow' => $id, 'violations' => $violations]);

                        return;
                    }

                    $workflow->enforceIsNew();

                    try {
                        $repository->save($workflow);
                    } catch (\Throwable $e) {
                        $logger->warning('application_blueprint.governance.workflow_seed_failed', ['workflow' => $id, 'error' => $e->getMessage()]);
                    }
                }

                private function topUpWorkflow(
                    \Waaseyaa\Workflows\Workflow $existing,
                    array $definition,
                    \Waaseyaa\Entity\Repository\EntityRepositoryInterface $repository,
                    \Waaseyaa\Foundation\Log\LoggerInterface $logger,
                ): void {
                    $addedStates = [];
                    foreach ($definition['states'] as $stateId => $stateData) {
                        if ($existing->hasState($stateId) || !\is_array($stateData)) {
                            continue;
                        }
                        $existing->addState(new \Waaseyaa\Workflows\WorkflowState(
                            id: (string) $stateId,
                            label: (string) ($stateData['label'] ?? $stateId),
                            weight: (int) ($stateData['weight'] ?? 0),
                            metadata: (array) ($stateData['metadata'] ?? []),
                            published: (bool) ($stateData['published'] ?? false),
                            defaultRevision: (bool) ($stateData['default_revision'] ?? false),
                        ));
                        $addedStates[] = $stateId;
                    }

                    $addedTransitions = [];
                    foreach ($definition['transitions'] as $transitionId => $transitionData) {
                        if ($existing->hasTransition($transitionId) || !\is_array($transitionData)) {
                            continue;
                        }
                        $existing->addTransition(new \Waaseyaa\Workflows\WorkflowTransition(
                            id: (string) $transitionId,
                            label: (string) ($transitionData['label'] ?? $transitionId),
                            from: (array) ($transitionData['from'] ?? []),
                            to: (string) ($transitionData['to'] ?? ''),
                            weight: (int) ($transitionData['weight'] ?? 0),
                            permission: (string) ($transitionData['permission'] ?? ''),
                        ));
                        $addedTransitions[] = $transitionId;
                    }

                    if ($addedStates === [] && $addedTransitions === []) {
                        return;
                    }

                    $violations = new \Waaseyaa\Workflows\Validation\WorkflowValidator()->validate($existing);
                    if ($violations !== []) {
                        $logger->warning('application_blueprint.governance.workflow_topup_invalid', ['violations' => $violations]);

                        return;
                    }

                    try {
                        $repository->save($existing);
                    } catch (\Throwable $e) {
                        $logger->warning('application_blueprint.governance.workflow_topup_failed', ['error' => $e->getMessage()]);
                    }
                }

            PHP;
    }

    private static function quoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function assertNoReservedRoleId(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        $index = 0;
        foreach ($blueprint->roles as $role) {
            if ($role->id === self::RESERVED_ADMINISTRATOR_ROLE) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::UnsupportedDeclaration,
                    "Blueprint role id \"administrator\" is reserved: Waaseyaa\\User\\User::hasPermission() treats the 'administrator' role as an unconditional bypass of every permission check, which a blueprint's declared 'permissions' list cannot express or limit.",
                    pointer: "/application_blueprint/roles/{$index}/id",
                );
            }
            ++$index;
        }

        if ($violations !== []) {
            throw new GenerationRefusalException(self::class, $violations);
        }
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
