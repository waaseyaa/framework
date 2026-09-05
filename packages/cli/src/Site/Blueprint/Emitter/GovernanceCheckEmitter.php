<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Blueprint\Emitter;

use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheck;
use Waaseyaa\SiteContract\Blueprint\BlueprintCheckKind;
use Waaseyaa\SiteContract\Blueprint\BlueprintEntity;
use Waaseyaa\SiteContract\Blueprint\BlueprintFixture;
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
 * `BlueprintCheck::$expect` is an unvalidated free-form string (the parser
 * and `ApplicationBlueprintValidator` never constrain its vocabulary): this
 * emitter treats any value starting with `grant` (case-insensitive, so
 * `granted` and a bare `grant`) as "expect allowed" and everything else
 * (`denied`, `deny`, or any other text) as "expect denied" — matching every
 * value the `complete.yaml` fixture actually uses (`granted`, `denied`,
 * `deny`).
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

    private static function expectAllowed(?string $expect): bool
    {
        return $expect !== null && stripos($expect, 'grant') === 0;
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

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Tests\\Blueprint;

            use PHPUnit\\Framework\\TestCase;
            use Waaseyaa\\Access\\EntityAccessHandler;
            use Waaseyaa\\Testing\\Factory\\AuthorizationPrincipalFactory;

            /**
             * Generated by Waaseyaa\\CLI\\Site\\Blueprint\\ApplicationBlueprintCompiler.
             * Do not edit by hand. Every blueprint entity must deny an anonymous and a
             * permission-less authenticated principal on every operation: no policy grants
             * by default, so absence of a matching condition must never leak into an
             * accidental Allowed.
             */
            final class GovernanceDefaultDenyTest extends TestCase
            {
            {$methods}}

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
        $method = 'test' . self::pascalCase($check->id);
        $assertion = self::expectAllowed($check->expect) ? 'assertTrue' : 'assertFalse';

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$this->quoted($check->role)});
                    self::assertNotNull(\$role);

                    \$account = AuthorizationPrincipalFactory::authenticated(1, roles: [\$role->id], permissions: \$role->permissions);

                    self::{$assertion}(\$account->hasPermission({$this->quoted($check->permission)}));
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
        $entity = $blueprint->entities[$check->entity];
        $entityClass = self::pascalCase($entity->id);
        $fixture = $check->fixture !== null ? ($blueprint->fixtures[$check->fixture] ?? null) : null;
        $method = 'test' . self::pascalCase($check->id);
        $assertion = self::expectAllowed($check->expect) ? 'assertTrue' : 'assertFalse';
        $policiesArg = isset($policyEntityIds[$entity->id]) ? "[new \\App\\Access\\{$entityClass}Policy()]" : '[]';
        $subjectValues = $this->renderSubjectValues($entity, $fixture);
        $operation = $check->operation->value;

        $callLine = $operation === 'create'
            ? "\$result = \$handler->checkCreateAccess({$this->quoted($entity->id)}, {$this->quoted($entity->id)}, \$account);"
            : "\$result = \$handler->check(\$subject, {$this->quoted($operation)}, \$account);";
        $subjectLine = $operation === 'create'
            ? ''
            : "        \$subject = new \\App\\Entity\\{$entityClass}({$subjectValues});\n";

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$this->quoted($check->role)});
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
            foreach ($fixture->values as $fieldId => $value) {
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
        $workflow = $blueprint->workflows[$check->workflow];
        $binding = $workflow->bindings[0] ?? null;
        \assert($binding !== null);
        $entity = $blueprint->entities[$binding->entity];
        $entityClass = self::pascalCase($entity->id);
        $method = 'test' . self::pascalCase($check->id);
        $allowed = self::expectAllowed($check->expect);

        $transitionCall = '$transitionService->transition($entity, ' . $this->quoted($check->transition) . ', $account);';
        $body = $allowed
            ? "        \$this->expectNotToPerformAssertions();\n        {$transitionCall}"
            : "        try {\n            {$transitionCall}\n            self::fail('Expected a TransitionDeniedException.');\n        } catch (TransitionDeniedException \$exception) {\n            self::assertSame(TransitionDeniedException::REASON_PERMISSION, \$exception->reason);\n        }";

        return <<<PHP

                public function {$method}(): void
                {
                    \$provider = new \\App\\Provider\\ApplicationBlueprintGovernanceServiceProvider();
                    \$repository = RoleRepository::fromProviders([\$provider]);
                    \$role = \$repository->get({$this->quoted($check->role)});
                    self::assertNotNull(\$role);
                    \$account = AuthorizationPrincipalFactory::authenticated(1, roles: [\$role->id], permissions: \$role->permissions);

                    [\$transitionService, \$entityRepository, \$temporaryDatabase] = \$this->bootTransitionService(
                        \\App\\Entity\\{$entityClass}::class,
                        {$this->quoted($entity->id)},
                        {$this->quoted($workflow->id)},
                        \\App\\Workflow\\{$this->pascalCase($workflow->id)}WorkflowDefinition::DEFINITION,
                    );
                    \$entity = \$entityRepository->create(['id' => 1]);
                    \$entity->enforceIsNew();
                    \$entityRepository->save(\$entity, validate: false);

            {$body}
                }

            PHP;
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
