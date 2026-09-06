<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheck;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheckKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintConditionKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintField;
use Waaseyaa\SiteContract\Blueprint\BlueprintFieldType;
use Waaseyaa\SiteContract\Blueprint\BlueprintFixture;
use Waaseyaa\SiteContract\Blueprint\BlueprintPolicy;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\SiteManifest;

/**
 * Emits the blueprint's generated behavioural companion tests under
 * `tests/Blueprint/` (#2788, FW-SITE-BLUEPRINT-01E): always
 * `GovernanceDefaultDenyTest.php`, plus `RolePermissionChecksTest.php`,
 * `WorkflowTransitionChecksTest.php`, and `EntityAccessChecksTest.php` only
 * when the blueprint declares a {@see BlueprintCheck} of that kind. Every
 * emitted path is also reported as a `companionTests` entry.
 *
 * `fixture_present` (`BlueprintCheckKind::FixturePresent`) is NOT emitted:
 * fixture materialization is 01D-3, a later slice — nothing seeds a blueprint
 * fixture as a real persisted row yet, so there is nothing to check its
 * presence against.
 *
 * `BlueprintCheck::$expect` is validated by
 * `ApplicationBlueprintParser` per check kind, with a DIFFERENT vocabulary
 * per kind (`ApplicationBlueprintParser::rolePermissionCheck/
 * workflowTransitionCheck/entityAccessCheck`): `role_permission` ->
 * `granted`/`denied`; `workflow_transition` -> `allowed`/`denied`;
 * `entity_access` -> `allow`/`deny`. This emitter's {@see self::expectAllowed()}
 * maps all three "allow" spellings (`granted`, `allowed`, `allow`) to "expect
 * allowed"; every other validated value means "expect denied".
 *
 * `GovernanceDefaultDenyTest` covers every blueprint entity — including one
 * with zero declared policies — with an `EntityAccessHandler` seeded with
 * that entity's generated policy when one exists (`[]` otherwise): an
 * anonymous and a permission-less authenticated principal must be denied
 * `view`/`update`/`delete`/`create`, and no principal is ever built with the
 * `administrator` role. This is emitted UNCONDITIONALLY, even when the
 * blueprint declares no governance at all (`minimal.yaml`) — the framework's
 * open-by-default invariant (no policy = no grant) is exactly as true, and
 * exactly as worth a regression test, for an entity with zero declared
 * policies as for one with several.
 *
 * `EntityAccessChecksTest` builds its subject entity IN MEMORY from the
 * check's referenced fixture's declared values, when one is named — but only
 * SCALAR field values: a relationship-typed fixture value names another
 * fixture id (e.g. `author: jane`), which fixture materialization (01D-3)
 * would resolve to a real persisted id; this slice has no fixture-seeding
 * step to resolve it against, so a relationship value is left unset (`null`)
 * on the in-memory subject rather than guessed at. `fixture.workflow_state`
 * (a plain string, not a fixture reference) IS carried onto the subject's
 * generated `workflow_state` field.
 *
 * `WorkflowTransitionChecksTest` wires a REAL `TransitionService` against a
 * `TemporarySqliteDatabase`-backed `EntityRepository` for the checked
 * workflow's bound entity type(s), mirroring
 * `Waaseyaa\Workflows\Tests\Integration\DepartmentRoutingFlowTest`'s
 * hand-wired provider-free construction. A denied transition asserts
 * `TransitionDeniedException::$reason === TransitionDeniedException::REASON_PERMISSION`
 * (every blueprint-declared transition permission is checked; no other
 * denial reason — unbound, unknown transition, illegal edge — is reachable
 * from a role/permission-shaped check).
 *
 * `JsonApiGovernanceChecksTest` (#2788 review gap 2) is emitted whenever the
 * blueprint declares at least one policy and drives EVERY entity through the
 * real `Waaseyaa\Api\JsonApiController` — see
 * {@see self::renderJsonApiGovernanceChecks()}.
 *
 * @api
 */
final class GovernanceCheckEmitter implements BlueprintArtifactEmitterInterface
{
    public function id(): string
    {
        return 'governance-checks';
    }

    public function emit(ApplicationBlueprint $blueprint, SiteManifest $manifest): BlueprintEmission
    {
        self::assertChecksAreCompileTimeSafe($blueprint);

        $policyEntityIds = self::entitiesWithPolicies($blueprint);

        $artifacts = [
            new GeneratedArtifact('tests/Blueprint/GovernanceDefaultDenyTest.php', $this->renderDefaultDeny($blueprint, $policyEntityIds)),
        ];

        $roleChecks = self::checksOfKind($blueprint, BlueprintCheckKind::RolePermission);
        if ($roleChecks !== []) {
            $artifacts[] = new GeneratedArtifact('tests/Blueprint/RolePermissionChecksTest.php', $this->renderRolePermissionChecks($roleChecks));
        }

        $entityChecks = self::checksOfKind($blueprint, BlueprintCheckKind::EntityAccess);
        if ($entityChecks !== []) {
            $artifacts[] = new GeneratedArtifact('tests/Blueprint/EntityAccessChecksTest.php', $this->renderEntityAccessChecks($blueprint, $entityChecks, $policyEntityIds));
        }

        $workflowChecks = self::checksOfKind($blueprint, BlueprintCheckKind::WorkflowTransition);
        if ($workflowChecks !== []) {
            $artifacts[] = new GeneratedArtifact('tests/Blueprint/WorkflowTransitionChecksTest.php', $this->renderWorkflowTransitionChecks($blueprint, $workflowChecks));
        }

        if ($blueprint->policies !== []) {
            $artifacts[] = new GeneratedArtifact('tests/Blueprint/JsonApiGovernanceChecksTest.php', $this->renderJsonApiGovernanceChecks($blueprint, $policyEntityIds));
        }

        usort($artifacts, static fn(GeneratedArtifact $left, GeneratedArtifact $right): int => strcmp($left->path, $right->path));
        $paths = array_map(static fn(GeneratedArtifact $artifact): string => $artifact->path, $artifacts);
        sort($paths, SORT_STRING);

        return new BlueprintEmission($artifacts, companionTests: $paths);
    }

    /** @return array<string, true> entity id => true, for every entity with a generated AccessPolicyEmitter class */
    private static function entitiesWithPolicies(ApplicationBlueprint $blueprint): array
    {
        $ids = [];
        foreach ($blueprint->policies as $policy) {
            $ids[$policy->entity] = true;
        }

        return $ids;
    }

    /** @return list<BlueprintCheck> sorted by id */
    private static function checksOfKind(ApplicationBlueprint $blueprint, BlueprintCheckKind $kind): array
    {
        $checks = array_values(array_filter(
            $blueprint->checks,
            static fn(BlueprintCheck $check): bool => $check->kind === $kind,
        ));
        usort($checks, static fn(BlueprintCheck $left, BlueprintCheck $right): int => strcmp($left->id, $right->id));

        return $checks;
    }

    /**
     * `$expect`'s allowed values differ per check kind (see class docblock),
     * but only three literal spellings ever mean "expect allowed":
     * `granted` (role_permission), `allowed` (workflow_transition), and
     * `allow` (entity_access). Every other validated value (`denied`,
     * `deny`) means "expect denied" (#2788 review F2 — a prior `stripos`
     * prefix-match against `'grant'` silently compiled a `deny`-shaped
     * expectation with `allow`/`allowed` in it as a positive check, and
     * NEVER matched `allow`/`allowed` themselves).
     */
    private static function expectAllowed(?string $expect): bool
    {
        return match ($expect) {
            'granted', 'allowed', 'allow' => true,
            default => false,
        };
    }

    /**
     * Two compile-time refusals raised before any artifact is rendered
     * (#2788 review F5, F6):
     *
     *  - F5: a `workflow_transition` check naming a workflow with zero
     *    declared bindings previously reached an unguarded
     *    `\assert($binding !== null)` in {@see self::renderWorkflowTransitionMethod()}
     *    — an `AssertionError` in a dev environment, a `TypeError` in
     *    production (`zend.assertions=-1` strips the assert, falling
     *    through to `$blueprint->entities[null]`). Neither is a coded
     *    refusal with a pointer.
     *  - F6: two checks of the SAME kind whose ids PascalCase to the same
     *    string emit a single companion test file with a duplicate method
     *    declaration (`'test' . self::pascalCase($check->id)`), which fails
     *    `php -l`. Collision is scoped per kind because each kind renders
     *    into its own class.
     */
    private static function assertChecksAreCompileTimeSafe(ApplicationBlueprint $blueprint): void
    {
        $violations = [];
        $methodNamesSeenByKind = [];
        $index = 0;
        foreach ($blueprint->checks as $check) {
            $path = "/application_blueprint/checks/{$index}";

            $methodName = 'test' . self::pascalCase($check->id);
            $seenKey = $check->kind->value . ':' . strtolower($methodName);
            if (isset($methodNamesSeenByKind[$seenKey])) {
                $violations[] = new GenerationViolation(
                    GenerationErrorCode::MaliciousIdentifier,
                    "Blueprint check \"{$check->id}\" PascalCases to the same generated test method name ({$methodName}) as check \"{$methodNamesSeenByKind[$seenKey]}\" within the same '{$check->kind->value}' companion test class.",
                    pointer: $path . '/id',
                );
            } else {
                $methodNamesSeenByKind[$seenKey] = $check->id;
            }

            if ($check->kind === BlueprintCheckKind::WorkflowTransition) {
                \assert($check->workflow !== null);
                $workflow = $blueprint->workflows[$check->workflow] ?? null;
                if ($workflow !== null && $workflow->bindings === []) {
                    $violations[] = new GenerationViolation(
                        GenerationErrorCode::UnsupportedDeclaration,
                        "Blueprint check \"{$check->id}\" targets workflow \"{$check->workflow}\", which declares zero bindings; a workflow_transition check requires the workflow to be bound to at least one entity.",
                        pointer: $path . '/workflow',
                    );
                }
            }

            ++$index;
        }

        if ($violations !== []) {
            throw new GenerationRefusalException(self::class, $violations);
        }
    }

    // -- GovernanceDefaultDenyTest -------------------------------------

    /** @param array<string, true> $policyEntityIds */
    private function renderDefaultDeny(ApplicationBlueprint $blueprint, array $policyEntityIds): string
    {
        $entities = array_values($blueprint->entities);
        usort($entities, static fn(BlueprintEntity $left, BlueprintEntity $right): int => strcmp($left->id, $right->id));

        $methods = implode('', array_map(
            fn(BlueprintEntity $entity): string => $this->renderDefaultDenyMethod($entity, isset($policyEntityIds[$entity->id])),
            $entities,
        ));

        // #2788 review F7: decision (i) assigns this class three more
        // invariants beyond the per-entity denial loop above, each rendered
        // only when the blueprint declares the governance it checks.
        $extraMethods = '';
        $extraUses = '';
        if ($blueprint->roles !== []) {
            $extraUses .= "use Waaseyaa\\User\\RoleRepository;\n";
            $extraMethods .= $this->renderNoAdministratorRoleMethod();
        }
        if ($blueprint->permissions !== []) {
            $extraMethods .= $this->renderEveryPermissionIsACatalogueConstantMethod($blueprint);
        }
        if ($policyEntityIds !== []) {
            $extraMethods .= $this->renderEveryPolicyIsDiscoverableMethod($policyEntityIds);
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Access\\EntityAccessHandler;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;
            {$extraUses}
            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Every blueprint entity must deny an anonymous and a
             * permission-less authenticated principal on every operation: no policy grants
             * by default, so absence of a matching condition must never leak into an
             * accidental Allowed. Also asserts the three companion invariants decision (i)
             * assigns to this class: no emitted role is ever `administrator`, every
             * permission a role/transition/policy references is a declared
             * `ApplicationBlueprintPermissions` constant, and every entity with a
             * declared policy has a REFLECTABLE `#[PolicyAttribute]` class (the gap that
             * let a policy compile correctly yet never be discovered at boot — #2788
             * review F1).
             */
            final class GovernanceDefaultDenyTest extends TestCase
            {
            {$methods}{$extraMethods}}

            PHP;
    }

    private function renderNoAdministratorRoleMethod(): string
    {
        return <<<'PHP'

                public function testNoEmittedRoleIsAdministrator(): void
                {
                    $provider = new \App\Provider\ApplicationBlueprintGovernanceServiceProvider();
                    $repository = RoleRepository::fromProviders([$provider]);
                    self::assertNull(
                        $repository->get('administrator'),
                        "A blueprint-generated role must never be named 'administrator' (Waaseyaa\\User\\User::hasPermission()'s bypass-all-permissions role).",
                    );
                }

            PHP;
    }

    private function renderEveryPermissionIsACatalogueConstantMethod(ApplicationBlueprint $blueprint): string
    {
        $referenced = [];
        foreach ($blueprint->roles as $role) {
            foreach ($role->permissions as $permission) {
                $referenced[$permission] = true;
            }
        }
        foreach ($blueprint->workflows as $workflow) {
            foreach ($workflow->transitions as $transition) {
                $referenced[$transition->permission] = true;
            }
        }
        foreach ($blueprint->policies as $policy) {
            if ($policy->condition->permission !== null) {
                $referenced[$policy->condition->permission] = true;
            }
        }
        $permissions = array_keys($referenced);
        sort($permissions, SORT_STRING);
        $literal = '[' . implode(', ', array_map(self::quoted(...), $permissions)) . ']';

        return <<<PHP

                public function testEveryReferencedPermissionIsACatalogueConstant(): void
                {
                    \$catalogue = array_values(new \\ReflectionClass(\\App\\Access\\ApplicationBlueprintPermissions::class)->getConstants());
                    foreach ({$literal} as \$permission) {
                        self::assertContains(\$permission, \$catalogue, "Permission '{\$permission}' referenced by a role, transition, or policy is not a declared ApplicationBlueprintPermissions constant.");
                    }
                }

            PHP;
    }

    /** @param array<string, true> $policyEntityIds entity id => true */
    private function renderEveryPolicyIsDiscoverableMethod(array $policyEntityIds): string
    {
        $entityIds = array_keys($policyEntityIds);
        sort($entityIds, SORT_STRING);
        $policyClasses = array_map(
            static fn(string $entityId): string => '\\App\\Access\\' . self::pascalCase($entityId) . 'Policy::class',
            $entityIds,
        );
        $literal = '[' . implode(', ', $policyClasses) . ']';

        return <<<PHP

                public function testEveryEntityWithAPolicyHasADiscoverablePolicyAttribute(): void
                {
                    foreach ({$literal} as \$policyClass) {
                        \$attributes = new \\ReflectionClass(\$policyClass)->getAttributes(\\Waaseyaa\\Access\\Gate\\PolicyAttribute::class);
                        self::assertNotSame([], \$attributes, "{\$policyClass} must carry #[PolicyAttribute] to be discovered at boot.");
                    }
                }

            PHP;
    }

    private function renderDefaultDenyMethod(BlueprintEntity $entity, bool $hasPolicy): string
    {
        $entityClass = self::pascalCase($entity->id);
        $method = 'test' . $entityClass . 'IsDeniedByDefault';
        $policiesArg = $hasPolicy ? "[new \\App\\Access\\{$entityClass}Policy()]" : '[]';
        $entityId = self::quoted($entity->id);

        return <<<PHP

                public function {$method}(): void
                {
                    \$handler = new EntityAccessHandler({$policiesArg});
                    \$subject = new \\App\\Entity\\{$entityClass}(['id' => 1]);

                    foreach ([AuthorizationPrincipalFactory::anonymous(), AuthorizationPrincipalFactory::authenticated(1)] as \$account) {
                        self::assertFalse(\$handler->check(\$subject, 'view', \$account)->isAllowed());
                        self::assertFalse(\$handler->check(\$subject, 'update', \$account)->isAllowed());
                        self::assertFalse(\$handler->check(\$subject, 'delete', \$account)->isAllowed());
                        self::assertFalse(\$handler->checkCreateAccess({$entityId}, {$entityId}, \$account)->isAllowed());
                    }
                }

            PHP;
    }

    // -- RolePermissionChecksTest ---------------------------------------

    /** @param list<BlueprintCheck> $checks */
    private function renderRolePermissionChecks(array $checks): string
    {
        $methods = implode('', array_map($this->renderRolePermissionMethod(...), $checks));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;
            use Waaseyaa\\User\\RoleRepository;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand.
             */
            final class RolePermissionChecksTest extends TestCase
            {
            {$methods}}

            PHP;
    }

    private function renderRolePermissionMethod(BlueprintCheck $check): string
    {
        \assert($check->role !== null && $check->permission !== null);
        $quoted = self::quoted(...);
        $method = 'test' . self::pascalCase($check->id);
        $assertion = self::expectAllowed($check->expect) ? 'assertTrue' : 'assertFalse';

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$quoted($check->role)});
                    self::assertNotNull(\$role);

                    \$account = AuthorizationPrincipalFactory::authenticated(1, roles: [\$role->id], permissions: \$role->permissions);

                    self::{$assertion}(\$account->hasPermission({$quoted($check->permission)}));
                }

            PHP;
    }

    // -- EntityAccessChecksTest ------------------------------------------

    /** @param list<BlueprintCheck> $checks @param array<string, true> $policyEntityIds */
    private function renderEntityAccessChecks(ApplicationBlueprint $blueprint, array $checks, array $policyEntityIds): string
    {
        $methods = implode('', array_map(
            fn(BlueprintCheck $check): string => $this->renderEntityAccessMethod($blueprint, $check, $policyEntityIds),
            $checks,
        ));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Access\\EntityAccessHandler;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;
            use Waaseyaa\\User\\RoleRepository;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand.
             */
            final class EntityAccessChecksTest extends TestCase
            {
            {$methods}}

            PHP;
    }

    /** @param array<string, true> $policyEntityIds */
    private function renderEntityAccessMethod(ApplicationBlueprint $blueprint, BlueprintCheck $check, array $policyEntityIds): string
    {
        \assert($check->role !== null && $check->entity !== null && $check->operation !== null);
        $quoted = self::quoted(...);
        $entity = $blueprint->entities[$check->entity];
        $entityClass = self::pascalCase($entity->id);
        $fixture = $check->fixture !== null ? ($blueprint->fixtures[$check->fixture] ?? null) : null;
        $method = 'test' . self::pascalCase($check->id);
        $assertion = self::expectAllowed($check->expect) ? 'assertTrue' : 'assertFalse';
        $policiesArg = isset($policyEntityIds[$entity->id]) ? "[new \\App\\Access\\{$entityClass}Policy()]" : '[]';
        $subjectValues = $this->renderSubjectValues($entity, $fixture);
        $operation = $check->operation->value;

        $callLine = $operation === 'create'
            ? "\$result = \$handler->checkCreateAccess({$quoted($entity->id)}, {$quoted($entity->id)}, \$account);"
            : "\$result = \$handler->check(\$subject, {$quoted($operation)}, \$account);";
        $subjectLine = $operation === 'create'
            ? ''
            : "        \$subject = new \\App\\Entity\\{$entityClass}({$subjectValues});\n";

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$quoted($check->role)});
                    self::assertNotNull(\$role);
                    \$account = AuthorizationPrincipalFactory::authenticated(1, roles: [\$role->id], permissions: \$role->permissions);

                    \$handler = new EntityAccessHandler({$policiesArg});
            {$subjectLine}        {$callLine}

                    self::{$assertion}(\$result->isAllowed());
                }

            PHP;
    }

    private function renderSubjectValues(BlueprintEntity $entity, ?BlueprintFixture $fixture): string
    {
        $entries = ["'id' => 1"];
        if ($fixture !== null) {
            // Emitters are pure functions of the CANONICAL blueprint: the
            // authored YAML and the published `.waaseyaa/site.yaml` (canonical
            // key order) parse into the same fixture with different value
            // order, and strict doctor recompiles from the published bytes.
            $values = $fixture->values;
            ksort($values, SORT_STRING);
            foreach ($values as $fieldId => $value) {
                if (!array_key_exists($fieldId, $entity->fields) || !is_scalar($value)) {
                    // A relationship-typed fixture value names another fixture id, not
                    // a resolvable real id (fixture materialization is 01D-3); left unset.
                    continue;
                }
                $entries[] = self::quoted($fieldId) . ' => ' . self::phpLiteral($value);
            }
            if ($fixture->workflowState !== null) {
                $entries[] = "'workflow_state' => " . self::quoted($fixture->workflowState);
            }
        }

        return '[' . implode(', ', $entries) . ']';
    }

    // -- WorkflowTransitionChecksTest -------------------------------------

    /** @param list<BlueprintCheck> $checks */
    private function renderWorkflowTransitionChecks(ApplicationBlueprint $blueprint, array $checks): string
    {
        $methods = implode('', array_map(
            fn(BlueprintCheck $check): string => $this->renderWorkflowTransitionMethod($blueprint, $check),
            $checks,
        ));

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Config\\ConfigFactory;
            use Waaseyaa\\Config\\Storage\\MemoryStorage;
            use Waaseyaa\\Entity\\EntityType;
            use Waaseyaa\\Entity\\EntityTypeManager;
            use Waaseyaa\\Entity\\EntityTypeInterface;
            use Waaseyaa\\Entity\\Repository\\EntityRepositoryInterface;
            use Waaseyaa\\EntityStorage\\Connection\\SingleConnectionResolver;
            use Waaseyaa\\EntityStorage\\Driver\\RevisionableStorageDriver;
            use Waaseyaa\\EntityStorage\\Driver\\SqlStorageDriver;
            use Waaseyaa\\EntityStorage\\SqlSchemaHandler;
            use Waaseyaa\\EntityStorage\\Testing\\EntityMutationAuthoritySchema;
            use Waaseyaa\\EntityStorage\\Testing\\V2EntityRepositoryFactory;
            use Waaseyaa\\Foundation\\Event\\SymfonyEventDispatcherAdapter;
            use Waaseyaa\\Testing\\Database\\TemporarySqliteDatabase;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;
            use Waaseyaa\\User\\RoleRepository;
            use Waaseyaa\\Workflows\\Binding\\WorkflowBindingResolver;
            use Waaseyaa\\Workflows\\Read\\WorkflowEntitySnapshotReader;
            use Waaseyaa\\Workflows\\Transition\\TransitionDeniedException;
            use Waaseyaa\\Workflows\\Transition\\TransitionService;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Wires a real TransitionService against a
             * TemporarySqliteDatabase-backed EntityRepository, mirroring
             * Waaseyaa\\Workflows\\Tests\\Integration\\DepartmentRoutingFlowTest's
             * hand-wired, provider-free construction.
             */
            final class WorkflowTransitionChecksTest extends TestCase
            {
            {$methods}
                /**
                 * The returned TemporarySqliteDatabase MUST be kept alive by the caller for
                 * as long as the repository is used: its destructor deletes the backing
                 * SQLite file, and nothing else in this array holds a reference to it.
                 *
                 * @return array{0: TransitionService, 1: EntityRepositoryInterface, 2: TemporarySqliteDatabase}
                 */
                private function bootTransitionService(string \$entityClass, string \$entityId, string \$workflowId, array \$workflowDefinition): array
                {
                    \$dispatcher = new SymfonyEventDispatcherAdapter();
                    \$database = new TemporarySqliteDatabase();
                    \$resolver = new SingleConnectionResolver(\$database->database());
                    EntityMutationAuthoritySchema::ensure(\$database->database());

                    \$definition = EntityType::fromClass(\$entityClass, revisionable: true);
                    \$schemaHandler = new SqlSchemaHandler(\$definition, \$database->database());
                    \$schemaHandler->ensureTable();
                    \$schemaHandler->ensureRevisionTable();
                    \$repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
                        \$definition,
                        new SqlStorageDriver(\$resolver, \$definition->getKeys()['id']),
                        \$dispatcher,
                        new RevisionableStorageDriver(\$resolver, \$definition),
                        \$database->database(),
                    );

                    // Mirrors WorkflowServiceProvider::register()'s 'workflow' EntityType
                    // declaration (docs/specs/content-workflow.md): the bound content
                    // entity's own workflow-state condition is checked purely in-memory
                    // (AuthorizationInputReader), but WorkflowBindingResolver itself always
                    // loads the bound Workflow config entity through a real repository.
                    \$workflowType = new EntityType(
                        id: 'workflow',
                        label: 'Workflow',
                        class: \\Waaseyaa\\Workflows\\Workflow::class,
                        keys: ['id' => 'id', 'label' => 'label'],
                        _fieldDefinitions: [
                            'id' => ['type' => 'string', 'read' => \\Waaseyaa\\Entity\\FieldReadLevel::Public],
                            'label' => ['type' => 'string', 'read' => \\Waaseyaa\\Entity\\FieldReadLevel::Public],
                            'initial_state' => ['type' => 'string', 'stored' => \\Waaseyaa\\Field\\FieldStorage::Data, 'read' => \\Waaseyaa\\Entity\\FieldReadLevel::Public],
                            'states' => ['type' => 'json', 'stored' => \\Waaseyaa\\Field\\FieldStorage::Data, 'read' => \\Waaseyaa\\Entity\\FieldReadLevel::Public],
                            'transitions' => ['type' => 'json', 'stored' => \\Waaseyaa\\Field\\FieldStorage::Data, 'read' => \\Waaseyaa\\Entity\\FieldReadLevel::Public],
                        ],
                    );
                    \$workflowSchemaHandler = new SqlSchemaHandler(\$workflowType, \$database->database());
                    \$workflowSchemaHandler->ensureTable();
                    \$workflowRepository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
                        \$workflowType,
                        new SqlStorageDriver(\$resolver, 'id'),
                        \$dispatcher,
                        null,
                        \$database->database(),
                    );
                    \$workflow = \$workflowRepository->create(\$workflowDefinition);
                    \$workflow->enforceIsNew();
                    \$workflowRepository->save(\$workflow, validate: false);

                    \$repositories = [\$entityId => \$repository, 'workflow' => \$workflowRepository];
                    \$entityTypeManager = new EntityTypeManager(
                        \$dispatcher,
                        repositoryFactory: static fn(string \$typeId, EntityTypeInterface \$def): EntityRepositoryInterface => \$repositories[\$typeId],
                    );
                    \$entityTypeManager->registerEntityType(\$definition);
                    \$entityTypeManager->registerEntityType(\$workflowType);

                    \$configStorage = new MemoryStorage();
                    \$configStorage->write('workflows.assignments', ["{\$entityId}.{\$entityId}" => \$workflowId]);
                    \$configFactory = new ConfigFactory(\$configStorage, \$dispatcher);

                    \$bindings = new WorkflowBindingResolver(\$configFactory, \$entityTypeManager);
                    \$transitionService = new TransitionService(
                        bindings: \$bindings,
                        entityTypeManager: \$entityTypeManager,
                        workflowValues: new WorkflowEntitySnapshotReader(),
                    );

                    return [\$transitionService, \$repository, \$database];
                }
            }

            PHP;
    }

    private function renderWorkflowTransitionMethod(ApplicationBlueprint $blueprint, BlueprintCheck $check): string
    {
        \assert($check->role !== null && $check->workflow !== null && $check->transition !== null);
        $quoted = self::quoted(...);
        $pascal = self::pascalCase(...);
        $workflow = $blueprint->workflows[$check->workflow];
        // A workflow with zero bindings is refused by
        // self::assertChecksAreCompileTimeSafe() (#2788 review F5) before
        // this method is ever reached — this assert is a defensive
        // invariant, not the primary refusal path.
        $binding = $workflow->bindings[0] ?? null;
        \assert($binding !== null);
        $entity = $blueprint->entities[$binding->entity];
        $entityClass = self::pascalCase($entity->id);
        $method = 'test' . self::pascalCase($check->id);
        $allowed = self::expectAllowed($check->expect);

        $transitionCall = '$transitionService->transition($entity, ' . self::quoted($check->transition) . ', $account);';
        $body = $allowed
            ? "        \$this->expectNotToPerformAssertions();\n        {$transitionCall}"
            : "        try {\n            {$transitionCall}\n            self::fail('Expected a TransitionDeniedException.');\n        } catch (TransitionDeniedException \$exception) {\n            self::assertSame(TransitionDeniedException::REASON_PERMISSION, \$exception->reason);\n        }";

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$quoted($check->role)});
                    // A NATIVE assert, not a PHPUnit assertion: an 'allowed'-expecting
                    // method below calls expectNotToPerformAssertions(), which fails the
                    // test (risky: "performed N assertions") if ANY PHPUnit assertion runs
                    // anywhere in the method, including a self::assertNotNull() guard here.
                    \assert(\$role !== null);
                    \$account = AuthorizationPrincipalFactory::authenticated(1, roles: [\$role->id], permissions: \$role->permissions);

                    [\$transitionService, \$entityRepository, \$temporaryDatabase] = \$this->bootTransitionService(
                        \\App\\Entity\\{$entityClass}::class,
                        {$quoted($entity->id)},
                        {$quoted($workflow->id)},
                        \\App\\Workflow\\{$pascal($workflow->id)}WorkflowDefinition::DEFINITION,
                    );
                    \$entity = \$entityRepository->create(['id' => 1]);
                    \$entity->enforceIsNew();
                    \$entityRepository->save(\$entity, validate: false);

            {$body}
                }

            PHP;
    }

    // -- JsonApiGovernanceChecksTest ---------------------------------------

    /**
     * Emitted whenever the blueprint declares at least one policy (#2788
     * review gap 2): every blueprint entity is driven through the REAL
     * `Waaseyaa\Api\JsonApiController` — `store()`, `index()`, `show()`,
     * `update()`, `destroy()` — composed with a real `EntityAccessHandler`
     * over that entity's generated policy (or none), a
     * `TemporarySqliteDatabase`-backed repository, and immutable principals.
     *
     * Per entity and operation this renders (a) the canonical denial — a
     * permission-less authenticated principal, plus anonymous for `show` —
     * asserting the controller's exact status/document shape (`403`
     * forbidden for writes, `404` non-oracle not-found for a concealed read,
     * `200` with an empty collection for a filtered list); (b) when the
     * entity declares a policy for that operation, the allowed path built
     * from the first declared policy (permission held, ownership satisfied,
     * workflow state matched) with exact status/document assertions; and (c)
     * for `ownership`/`workflow_state` conditions, a near-miss denial (holds
     * the permission, but is not the owner / not in a listed state). The
     * allowed `update` path additionally proves the field-level seal
     * (review gap 3): reassigning `keys.owner` or writing `workflow_state`
     * through an entity-level update grant is `403`.
     *
     * @param array<string, true> $policyEntityIds
     */
    private function renderJsonApiGovernanceChecks(ApplicationBlueprint $blueprint, array $policyEntityIds): string
    {
        $entities = array_values($blueprint->entities);
        usort($entities, static fn(BlueprintEntity $left, BlueprintEntity $right): int => strcmp($left->id, $right->id));

        $methods = '';
        foreach ($entities as $entity) {
            $methods .= $this->renderJsonApiEntityMethods($blueprint, $entity, isset($policyEntityIds[$entity->id]));
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Access\\AccountInterface;
            use Waaseyaa\\Access\\EntityAccessHandler;
            use Waaseyaa\\Api\\JsonApiController;
            use Waaseyaa\\Api\\ResourceSerializer;
            use Waaseyaa\\Entity\\EntityType;
            use Waaseyaa\\Entity\\EntityTypeInterface;
            use Waaseyaa\\Entity\\EntityTypeManager;
            use Waaseyaa\\Entity\\Repository\\EntityRepositoryInterface;
            use Waaseyaa\\EntityStorage\\Connection\\SingleConnectionResolver;
            use Waaseyaa\\EntityStorage\\Driver\\RevisionableStorageDriver;
            use Waaseyaa\\EntityStorage\\Driver\\SqlStorageDriver;
            use Waaseyaa\\EntityStorage\\SqlSchemaHandler;
            use Waaseyaa\\EntityStorage\\Testing\\EntityMutationAuthoritySchema;
            use Waaseyaa\\EntityStorage\\Testing\\V2EntityRepositoryFactory;
            use Waaseyaa\\Foundation\\Event\\SymfonyEventDispatcherAdapter;
            use Waaseyaa\\Testing\\Database\\TemporarySqliteDatabase;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Drives every blueprint entity through the real
             * JsonApiController (store/index/show/update/destroy) composed with a real
             * EntityAccessHandler over the generated policy, asserting the exact status
             * and document shape for a denied principal, and for an allowed principal
             * wherever the blueprint declares a policy for that operation. Reads that are
             * denied answer the canonical not-found shape, never an existence oracle.
             */
            final class JsonApiGovernanceChecksTest extends TestCase
            {
            {$methods}
                /**
                 * The returned TemporarySqliteDatabase MUST be kept alive by the caller for
                 * as long as the controller is used: its destructor deletes the backing
                 * SQLite file.
                 *
                 * @param list<\\Waaseyaa\\Access\\AccessPolicyInterface> \$policies
                 * @return array{0: JsonApiController, 1: EntityRepositoryInterface, 2: TemporarySqliteDatabase}
                 */
                private function bootJsonApi(string \$entityClass, string \$entityId, bool \$revisionable, array \$policies, AccountInterface \$account): array
                {
                    \$dispatcher = new SymfonyEventDispatcherAdapter();
                    \$database = new TemporarySqliteDatabase();
                    \$resolver = new SingleConnectionResolver(\$database->database());
                    EntityMutationAuthoritySchema::ensure(\$database->database());
                    \$handler = new EntityAccessHandler(\$policies);

                    \$definition = EntityType::fromClass(\$entityClass, revisionable: \$revisionable);
                    \$schemaHandler = new SqlSchemaHandler(\$definition, \$database->database());
                    \$schemaHandler->ensureTable();
                    \$revisionDriver = null;
                    if (\$revisionable) {
                        \$schemaHandler->ensureRevisionTable();
                        \$revisionDriver = new RevisionableStorageDriver(\$resolver, \$definition);
                    }
                    \$repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
                        \$definition,
                        new SqlStorageDriver(\$resolver, \$definition->getKeys()['id']),
                        \$dispatcher,
                        \$revisionDriver,
                        \$database->database(),
                        accessHandler: \$handler,
                    );
                    \$entityTypeManager = new EntityTypeManager(
                        \$dispatcher,
                        repositoryFactory: static fn(string \$typeId, EntityTypeInterface \$def): EntityRepositoryInterface => \$repository,
                    );
                    \$entityTypeManager->registerEntityType(\$definition);

                    \$controller = new JsonApiController(\$entityTypeManager, new ResourceSerializer(\$entityTypeManager), \$handler, \$account);

                    return [\$controller, \$repository, \$database];
                }

                /** @param array<string, mixed> \$values */
                private function seedSubject(EntityRepositoryInterface \$repository, array \$values): void
                {
                    \$entity = \$repository->create(\$values);
                    \$entity->enforceIsNew();
                    \$repository->save(\$entity, validate: false);
                }
            }

            PHP;
    }

    private function renderJsonApiEntityMethods(ApplicationBlueprint $blueprint, BlueprintEntity $entity, bool $hasPolicy): string
    {
        $quoted = self::quoted(...);
        $pascal = self::pascalCase(...);
        $entityClass = self::pascalCase($entity->id);
        $entityId = self::quoted($entity->id);
        $classLiteral = "\\App\\Entity\\{$entityClass}::class";
        $revisionable = $entity->revisionable ? 'true' : 'false';
        $policiesArg = $hasPolicy ? "[new \\App\\Access\\{$entityClass}Policy()]" : '[]';
        $labelField = $entity->keys->label;
        $ownerField = $entity->keys->owner;
        $workflow = self::boundWorkflow($blueprint, $entity->id);

        $byOperation = [];
        foreach ($blueprint->policies as $policy) {
            if ($policy->entity === $entity->id) {
                $byOperation[$policy->operation->value][] = $policy;
            }
        }
        foreach ($byOperation as &$list) {
            usort($list, static fn(BlueprintPolicy $left, BlueprintPolicy $right): int => strcmp($left->id, $right->id));
        }
        unset($list);

        $boot = fn(string $accountExpr): string => "        [\$controller, \$repository, \$database] = \$this->bootJsonApi({$classLiteral}, {$entityId}, {$revisionable}, {$policiesArg}, {$accountExpr});\n";
        $denied = 'AuthorizationPrincipalFactory::authenticated(7)';
        $subject = fn(int $ownerId, ?string $state): string => $this->renderJsonApiSubject($entity, $ownerId, $state, $workflow?->initialState);
        $createPayload = fn(int $ownerId): string => $this->renderJsonApiCreatePayload($blueprint, $entity, $ownerId);
        $patch = fn(string $field, string $valueLiteral): string => "['data' => ['type' => {$entityId}, 'attributes' => [" . self::quoted($field) . " => {$valueLiteral}]]]";

        $methods = '';

        // -- create --------------------------------------------------------
        $methods .= <<<PHP

                public function test{$entityClass}CreateIsDeniedWithoutAGrant(): void
                {
            {$boot($denied)}        \$document = \$controller->store({$entityId}, {$createPayload(7)});

                    self::assertSame(403, \$document->statusCode);
                    self::assertSame('403', \$document->toArray()['errors'][0]['status']);
                }

            PHP;
        if (isset($byOperation['create'])) {
            $policy = $byOperation['create'][0];
            $methods .= <<<PHP

                    public function test{$entityClass}CreateIsAllowedBy{$pascal($policy->id)}(): void
                    {
                {$boot($this->renderJsonApiPrincipal(42, $policy))}        \$document = \$controller->store({$entityId}, {$createPayload(42)});

                        self::assertSame(201, \$document->statusCode);
                        self::assertSame('Created', \$document->toArray()['data']['attributes'][{$quoted($labelField)}]);
                    }

                PHP;
        }

        // -- list ----------------------------------------------------------
        $methods .= <<<PHP

                public function test{$entityClass}ListIsEmptyWithoutAGrant(): void
                {
            {$boot($denied)}        \$this->seedSubject(\$repository, {$subject(42, null)});
                    \$document = \$controller->index({$entityId});

                    self::assertSame(200, \$document->statusCode);
                    self::assertSame([], \$document->toArray()['data']);
                    self::assertSame(0, \$document->toArray()['meta']['total']);
                }

            PHP;
        if (isset($byOperation['view'])) {
            $policy = $byOperation['view'][0];
            $methods .= <<<PHP

                    public function test{$entityClass}ListIsAllowedBy{$pascal($policy->id)}(): void
                    {
                {$boot($this->renderJsonApiPrincipal(42, $policy))}        \$this->seedSubject(\$repository, {$subject(42, self::stateFor($policy, $workflow))});
                        \$document = \$controller->index({$entityId});

                        self::assertSame(200, \$document->statusCode);
                        self::assertCount(1, \$document->toArray()['data']);
                        self::assertSame(1, \$document->toArray()['meta']['total']);
                        self::assertSame('Welcome', \$document->toArray()['data'][0]['attributes'][{$quoted($labelField)}]);
                    }

                PHP;
        }

        // -- show ----------------------------------------------------------
        $methods .= <<<PHP

                public function test{$entityClass}ShowIsConcealedWithoutAGrant(): void
                {
                    foreach ([{$denied}, AuthorizationPrincipalFactory::anonymous()] as \$account) {
            {$this->indent($boot('$account'), 4)}            \$this->seedSubject(\$repository, {$subject(42, null)});
                        \$document = \$controller->show({$entityId}, 1);

                        // A denied read answers the canonical not-found shape, never an existence oracle.
                        self::assertSame(404, \$document->statusCode);
                        self::assertSame('404', \$document->toArray()['errors'][0]['status']);
                        self::assertArrayNotHasKey('code', \$document->toArray()['errors'][0]);
                    }
                }

            PHP;
        if (isset($byOperation['view'])) {
            $policy = $byOperation['view'][0];
            $concealed = '';
            foreach (array_values(array_filter([$ownerField, $workflow !== null ? 'workflow_state' : null], static fn(?string $field): bool => $field !== null)) as $protected) {
                $concealed .= "        self::assertArrayNotHasKey({$quoted($protected)}, \$attributes, 'authorization inputs never enter the ordinary projection');\n";
            }
            $methods .= <<<PHP

                    public function test{$entityClass}ShowIsAllowedBy{$pascal($policy->id)}(): void
                    {
                {$boot($this->renderJsonApiPrincipal(42, $policy))}        \$this->seedSubject(\$repository, {$subject(42, self::stateFor($policy, $workflow))});
                        \$document = \$controller->show({$entityId}, 1);

                        self::assertSame(200, \$document->statusCode);
                        \$attributes = \$document->toArray()['data']['attributes'];
                        self::assertSame('Welcome', \$attributes[{$quoted($labelField)}]);
                {$concealed}    }

                PHP;
            $methods .= $this->renderJsonApiNearMiss($entity, 'Show', $policy, $workflow, $boot, $subject, "        \$document = \$controller->show({$entityId}, 1);\n\n        self::assertSame(404, \$document->statusCode);\n");
        }

        // -- update --------------------------------------------------------
        $updateCall = "\$controller->update({$entityId}, 1, {$patch($labelField, "'Updated'")})";
        $methods .= <<<PHP

                public function test{$entityClass}UpdateIsDeniedWithoutAGrant(): void
                {
            {$boot($denied)}        \$this->seedSubject(\$repository, {$subject(42, null)});
                    \$document = {$updateCall};

                    self::assertSame(403, \$document->statusCode);
                    self::assertSame('403', \$document->toArray()['errors'][0]['status']);
                }

            PHP;
        if (isset($byOperation['update'])) {
            $policy = $byOperation['update'][0];
            $sealed = '';
            if ($ownerField !== null) {
                $sealed .= "\n        // Review gap 3: the entity-level grant must not reassign the owner it was decided on.\n"
                    . "        self::assertSame(403, \$controller->update({$entityId}, 1, {$patch($ownerField, '999')})->statusCode);\n";
            }
            if ($workflow !== null) {
                $seededState = self::stateFor($policy, $workflow);
                $moved = self::otherState($workflow, $seededState === null ? [] : [$seededState]) ?? $seededState ?? $workflow->initialState;
                $sealed .= "        // The workflow selector is engine-owned; only a transition moves it.\n"
                    . "        self::assertSame(403, \$controller->update({$entityId}, 1, {$patch('workflow_state', self::quoted($moved))})->statusCode);\n";
            }
            $methods .= <<<PHP

                    public function test{$entityClass}UpdateIsAllowedBy{$pascal($policy->id)}(): void
                    {
                {$boot($this->renderJsonApiPrincipal(42, $policy))}        \$this->seedSubject(\$repository, {$subject(42, self::stateFor($policy, $workflow))});
                        \$document = {$updateCall};

                        self::assertSame(200, \$document->statusCode);
                        self::assertSame('Updated', \$document->toArray()['data']['attributes'][{$quoted($labelField)}]);
                {$sealed}    }

                PHP;
            $methods .= $this->renderJsonApiNearMiss($entity, 'Update', $policy, $workflow, $boot, $subject, "        \$document = {$updateCall};\n\n        self::assertSame(403, \$document->statusCode);\n");
        }

        // -- delete --------------------------------------------------------
        $methods .= <<<PHP

                public function test{$entityClass}DeleteIsDeniedWithoutAGrant(): void
                {
            {$boot($denied)}        \$this->seedSubject(\$repository, {$subject(42, null)});
                    \$document = \$controller->destroy({$entityId}, 1);

                    self::assertSame(403, \$document->statusCode);
                    self::assertSame('403', \$document->toArray()['errors'][0]['status']);
                }

            PHP;
        if (isset($byOperation['delete'])) {
            $policy = $byOperation['delete'][0];
            $methods .= <<<PHP

                    public function test{$entityClass}DeleteIsAllowedBy{$pascal($policy->id)}(): void
                    {
                {$boot($this->renderJsonApiPrincipal(42, $policy))}        \$this->seedSubject(\$repository, {$subject(42, self::stateFor($policy, $workflow))});
                        \$document = \$controller->destroy({$entityId}, 1);

                        self::assertSame(204, \$document->statusCode);
                        self::assertNull(\$repository->find(1));
                    }

                PHP;
            $methods .= $this->renderJsonApiNearMiss($entity, 'Delete', $policy, $workflow, $boot, $subject, "        \$document = \$controller->destroy({$entityId}, 1);\n\n        self::assertSame(403, \$document->statusCode);\n");
        }

        return $methods;
    }

    /**
     * A near-miss denial for an `ownership` or `workflow_state` condition:
     * the principal HOLDS the condition's permission but is not the owner /
     * the subject is not in a listed state, so the grant must still not
     * fire. `permission` conditions have no near miss beyond the base
     * permission-less denial.
     *
     * @param \Closure(string): string $boot
     * @param \Closure(int, ?string): string $subject
     */
    private function renderJsonApiNearMiss(BlueprintEntity $entity, string $operation, BlueprintPolicy $policy, ?BlueprintWorkflow $workflow, \Closure $boot, \Closure $subject, string $body): string
    {
        $entityClass = self::pascalCase($entity->id);
        $condition = $policy->condition;
        if ($condition->kind === BlueprintConditionKind::Ownership) {
            $method = "test{$entityClass}{$operation}IsDeniedToANonOwnerHoldingThePermission";
            $seed = $subject(42, self::stateFor($policy, $workflow));
            $principal = $this->renderJsonApiPrincipal(7, $policy);
        } elseif ($condition->kind === BlueprintConditionKind::WorkflowState && $workflow !== null) {
            $other = self::otherState($workflow, $condition->states ?? []);
            if ($other === null) {
                return '';
            }
            $method = "test{$entityClass}{$operation}IsDeniedOutsideTheListedWorkflowStates";
            $seed = $subject(42, $other);
            $principal = $this->renderJsonApiPrincipal(42, $policy);
        } else {
            return '';
        }

        return <<<PHP

                public function {$method}(): void
                {
            {$boot($principal)}        \$this->seedSubject(\$repository, {$seed});
            {$body}    }

            PHP;
    }

    private function renderJsonApiPrincipal(int $id, BlueprintPolicy $policy): string
    {
        $permission = $policy->condition->permission;
        $permissions = $permission === null ? '[]' : '[' . self::quoted($permission) . ']';

        return "AuthorizationPrincipalFactory::authenticated({$id}, permissions: {$permissions})";
    }

    /** The persisted subject row: label, owner (when declared) and workflow state (when bound). */
    private function renderJsonApiSubject(BlueprintEntity $entity, int $ownerId, ?string $state, ?string $initialState): string
    {
        $entries = ["'id' => 1", self::quoted($entity->keys->label) . " => 'Welcome'"];
        if ($entity->keys->owner !== null) {
            $entries[] = self::quoted($entity->keys->owner) . " => {$ownerId}";
        }
        $effectiveState = $state ?? $initialState;
        if ($effectiveState !== null) {
            $entries[] = "'workflow_state' => " . self::quoted($effectiveState);
        }

        return '[' . implode(', ', $entries) . ']';
    }

    /**
     * A create payload satisfying every required declared field and
     * relationship, with the owner set to the acting principal (create-time
     * authorship, the `NodeAccessPolicy` precedent).
     */
    private function renderJsonApiCreatePayload(ApplicationBlueprint $blueprint, BlueprintEntity $entity, int $ownerId): string
    {
        $entries = [self::quoted($entity->keys->label) . " => 'Created'"];
        foreach (self::sortedEntityFields($entity) as $field) {
            if (!$field->required || $field->id === $entity->keys->label) {
                continue;
            }
            $entries[] = self::quoted($field->id) . ' => ' . self::sampleLiteral($field);
        }
        $relationships = array_values($blueprint->relationships);
        usort($relationships, static fn($left, $right): int => strcmp($left->id, $right->id));
        foreach ($relationships as $relationship) {
            if ($relationship->fromEntity !== $entity->id) {
                continue;
            }
            if ($relationship->fromField === $entity->keys->owner) {
                $entries[] = self::quoted($relationship->fromField) . " => {$ownerId}";
            } elseif ($relationship->required) {
                $entries[] = self::quoted($relationship->fromField) . ' => 1';
            }
        }

        return "['data' => ['type' => " . self::quoted($entity->id) . ", 'attributes' => [" . implode(', ', $entries) . ']]]';
    }

    /** @return list<BlueprintField> sorted by id */
    private static function sortedEntityFields(BlueprintEntity $entity): array
    {
        $fields = array_values($entity->fields);
        usort($fields, static fn(BlueprintField $left, BlueprintField $right): int => strcmp($left->id, $right->id));

        return $fields;
    }

    private static function sampleLiteral(BlueprintField $field): string
    {
        return match ($field->type) {
            BlueprintFieldType::String, BlueprintFieldType::Text => "'Sample'",
            BlueprintFieldType::Integer => '1',
            BlueprintFieldType::Float, BlueprintFieldType::Decimal => '1.5',
            BlueprintFieldType::Boolean => 'true',
            BlueprintFieldType::Date => "'2026-01-01'",
            BlueprintFieldType::DateTime => "'2026-01-01T00:00:00+00:00'",
            BlueprintFieldType::Email => "'sample@example.test'",
            BlueprintFieldType::Link => "'https://example.test/'",
            BlueprintFieldType::Json, BlueprintFieldType::ListSelect => '[]',
            BlueprintFieldType::Enum => self::quoted(self::firstSorted($field->values ?? [''])),
        };
    }

    private static function boundWorkflow(ApplicationBlueprint $blueprint, string $entityId): ?BlueprintWorkflow
    {
        foreach ($blueprint->workflows as $workflow) {
            foreach ($workflow->bindings as $binding) {
                if ($binding->entity === $entityId) {
                    return $workflow;
                }
            }
        }

        return null;
    }

    /** The workflow state that satisfies the policy: a listed state for `workflow_state`, otherwise the initial state. */
    private static function stateFor(BlueprintPolicy $policy, ?BlueprintWorkflow $workflow): ?string
    {
        if ($workflow === null) {
            return null;
        }
        if ($policy->condition->kind === BlueprintConditionKind::WorkflowState && ($policy->condition->states ?? []) !== []) {
            return self::firstSorted($policy->condition->states);
        }

        return $workflow->initialState;
    }

    /** @param list<string> $excluded */
    private static function otherState(BlueprintWorkflow $workflow, array $excluded): ?string
    {
        $ids = array_keys($workflow->states);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (!in_array($id, $excluded, true)) {
                return $id;
            }
        }

        return null;
    }

    /** @param list<string> $values */
    private static function firstSorted(array $values): string
    {
        sort($values, SORT_STRING);

        return $values[0];
    }

    private function indent(string $code, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return implode("\n", array_map(static fn(string $line): string => $line === '' ? '' : $pad . $line, explode("\n", rtrim($code, "\n")))) . "\n";
    }

    private static function quoted(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private static function phpLiteral(bool|int|float|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return self::quoted($value);
    }

    private static function pascalCase(string $id): string
    {
        return str_replace('_', '', ucwords(str_replace('-', '_', $id), '_'));
    }
}
