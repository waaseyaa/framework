<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\WorkflowDefinitionEmitter;
use Waaseyaa\Config\Sync\ConfigSyncDeserializer;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflow;
use Waaseyaa\SiteContract\Blueprint\BlueprintWorkflowState;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\Workflows\Config\WorkflowAssignmentsConfig;
use Waaseyaa\Workflows\Validation\WorkflowValidator;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(WorkflowDefinitionEmitter::class)]
final class WorkflowDefinitionEmitterTest extends TestCase
{
    #[Test]
    public function idIsStable(): void
    {
        self::assertSame('workflow-definition', new WorkflowDefinitionEmitter()->id());
    }

    #[Test]
    public function itEmitsNothingWhenTheBlueprintDeclaresNoWorkflowsMatchingTheMinimalGoldenFixture(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $emission = new WorkflowDefinitionEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame([], $emission->artifacts);
        self::assertSame([], $emission->registrations);
        self::assertSame([], $emission->companionTests);
    }

    #[Test]
    public function itMatchesTheCompleteGoldenFixture(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new WorkflowDefinitionEmitter()->emit($manifest->applicationBlueprint, $manifest);

        self::assertSame(
            ['config/sync/workflows.assignments.yml', 'src/Workflow/EditorialWorkflowDefinition.php'],
            array_map(static fn($a) => $a->path, $emission->artifacts),
        );
        self::assertSame(
            $this->expected('complete/src/Workflow/EditorialWorkflowDefinition.php'),
            $this->content($emission->artifacts, 'src/Workflow/EditorialWorkflowDefinition.php'),
        );
        self::assertSame(
            $this->expected('complete/config/sync/workflows.assignments.yml'),
            $this->content($emission->artifacts, 'config/sync/workflows.assignments.yml'),
        );
    }

    #[Test]
    public function itEmitsAssignmentsAsAWritableCfg03SyncArtifactBoundToWorkflowAssignmentsSchema(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new WorkflowDefinitionEmitter()->emit($manifest->applicationBlueprint, $manifest);
        $yaml = $this->content($emission->artifacts, 'config/sync/workflows.assignments.yml');

        self::assertStringStartsWith("_meta:\n", $yaml);
        self::assertStringContainsString('schema_id: ' . WorkflowAssignmentsConfig::CONFIG_NAME, $yaml);
        self::assertStringContainsString('entity_type: workflows', $yaml);
        self::assertStringContainsString('entity_id: assignments', $yaml);
        self::assertStringContainsString('article.article: editorial', $yaml);

        $file = new ConfigSyncDeserializer()->fromYaml($yaml, 'workflows.assignments.yml');
        self::assertSame('workflows', $file->entityType);
        self::assertSame('assignments', $file->entityId);
        self::assertTrue($file->isWritableV1());
        self::assertSame(['article.article' => 'editorial'], $file->fields);
        self::assertSame(
            ConfigSyncFile::deterministicUuid('workflows', 'assignments'),
            $file->uuid,
        );
    }

    /**
     * that passes the real structural `WorkflowValidator` — not merely a
     * byte-identical snapshot.
     */
    #[Test]
    public function theGeneratedDefinitionConstructsAValidWorkflowAndPermissionForIsVerbatim(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $emission = new WorkflowDefinitionEmitter()->emit($manifest->applicationBlueprint, $manifest);

        $namespace = 'Waaseyaa\\CLI\\Tests\\BlueprintWorkflowDefinition' . bin2hex(random_bytes(4));
        $source = str_replace(
            'namespace App\\Workflow;',
            'namespace ' . $namespace . ';',
            $this->content($emission->artifacts, 'src/Workflow/EditorialWorkflowDefinition.php'),
        );

        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_workflow_definition_') . '.php';
        file_put_contents($file, $source);
        try {
            require $file;
            $class = $namespace . '\\EditorialWorkflowDefinition';

            $workflow = new Workflow($class::DEFINITION);
            self::assertSame([], new WorkflowValidator()->validate($workflow));
            self::assertSame('draft', $workflow->getInitialState());
            self::assertTrue($workflow->hasState('draft'));
            self::assertTrue($workflow->hasState('published'));

            $transition = $workflow->getTransition('publish');
            self::assertNotNull($transition);
            self::assertSame('use editorial transition publish', $workflow->permissionFor($transition));
        } finally {
            unlink($file);
        }
    }

    /**
     * #2788 review F9: a blueprint declaring a workflow with ZERO bindings
     * must emit only the definition file, never an empty
     * `config/sync/workflows.assignments.yml` — an empty `{}` would seed a
     * trusted CFG-03 config entry for a binding nobody authored, turning a
     * later legitimately-authored assignment into an update instead of a
     * create.
     */
    #[Test]
    public function itEmitsNoAssignmentsArtifactWhenTheWorkflowHasZeroBindings(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $blueprint = $this->blueprintWithOneUnboundWorkflow();

        $emission = new WorkflowDefinitionEmitter()->emit($blueprint, $manifest);

        self::assertSame(
            ['src/Workflow/UnboundWorkflowDefinition.php'],
            array_map(static fn($a) => $a->path, $emission->artifacts),
        );
    }

    /**
     * #2848 convergence proof: `toDefinitionArray()` (the `scaffold:workflow`
     * entrypoint) and `renderDefinition()` (the blueprint-compiled PHP
     * source, exercised via `theGeneratedDefinitionConstructsAValidWorkflow...`
     * above) apply the same derivation rules to the same `complete.yaml`
     * `editorial` workflow — hydrating a `Workflow` from either one produces
     * the identical `initial_state`, states, and transitions.
     */
    #[Test]
    public function toDefinitionArrayConvergesWithTheGeneratedPhpSourceForTheSameWorkflow(): void
    {
        $manifest = $this->manifest('complete.yaml');
        $blueprint = $manifest->applicationBlueprint;
        $editorial = $blueprint->workflows['editorial'];

        $array = new WorkflowDefinitionEmitter()->toDefinitionArray($editorial);

        $fromArray = new Workflow($array);
        self::assertSame([], new WorkflowValidator()->validate($fromArray));

        $emission = new WorkflowDefinitionEmitter()->emit($blueprint, $manifest);
        $namespace = 'Waaseyaa\\CLI\\Tests\\WorkflowDefinitionConvergence' . bin2hex(random_bytes(4));
        $source = str_replace(
            'namespace App\\Workflow;',
            'namespace ' . $namespace . ';',
            $this->content($emission->artifacts, 'src/Workflow/EditorialWorkflowDefinition.php'),
        );
        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_workflow_definition_convergence_') . '.php';
        file_put_contents($file, $source);
        try {
            require $file;
            $fromPhpSource = new Workflow(($namespace . '\\EditorialWorkflowDefinition')::DEFINITION);

            self::assertSame($fromPhpSource->getInitialState(), $fromArray->getInitialState());
            self::assertSame(array_keys($fromPhpSource->getStates()), array_keys($fromArray->getStates()));
            self::assertSame(array_keys($fromPhpSource->getTransitions()), array_keys($fromArray->getTransitions()));
            foreach ($fromPhpSource->getTransitions() as $transitionId => $transition) {
                self::assertSame(
                    $fromPhpSource->permissionFor($transition),
                    $fromArray->permissionFor($fromArray->getTransition($transitionId)),
                );
            }
        } finally {
            unlink($file);
        }
    }

    /**
     * Two workflow ids that PascalCase to the same class name are refused
     * (`GEN006_MALICIOUS_IDENTIFIER`) before any artifact is emitted, rather
     * than silently producing a duplicate `src/Workflow/*.php` path within
     * this emitter's own artifact list (#2788 review F6).
     */
    #[Test]
    public function twoWorkflowIdsPascalCasingToTheSameClassNameAreRefusedGen006(): void
    {
        $manifest = $this->manifest('minimal.yaml');
        $blueprint = new ApplicationBlueprint(
            contractVersion: 1,
            entities: $manifest->applicationBlueprint->entities,
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: [
                'a_b' => $this->unboundWorkflow('a_b'),
                'ab' => $this->unboundWorkflow('ab'),
            ],
            fixtures: [],
            checks: [],
        );

        try {
            new WorkflowDefinitionEmitter()->emit($blueprint, $manifest);
            self::fail('Expected a GenerationRefusalException.');
        } catch (GenerationRefusalException $exception) {
            self::assertSame(GenerationErrorCode::MaliciousIdentifier, $exception->violations[0]->code);
        }
    }

    private function blueprintWithOneUnboundWorkflow(): ApplicationBlueprint
    {
        $manifest = $this->manifest('minimal.yaml');

        return new ApplicationBlueprint(
            contractVersion: 1,
            entities: $manifest->applicationBlueprint->entities,
            relationships: [],
            permissions: [],
            roles: [],
            policies: [],
            workflows: ['unbound' => $this->unboundWorkflow('unbound')],
            fixtures: [],
            checks: [],
        );
    }

    private function unboundWorkflow(string $id): BlueprintWorkflow
    {
        return new BlueprintWorkflow(
            id: $id,
            label: 'Unbound',
            initialState: 'draft',
            states: ['draft' => new BlueprintWorkflowState('draft', 'Draft', false)],
            transitions: [],
            bindings: [],
        );
    }

    /** @param list<\Waaseyaa\SiteContract\Generation\GeneratedArtifact> $artifacts */
    private function content(array $artifacts, string $path): string
    {
        foreach ($artifacts as $artifact) {
            if ($artifact->path === $path) {
                return $artifact->content;
            }
        }
        self::fail("No artifact at {$path}");
    }

    private function manifest(string $fixture): SiteManifest
    {
        $yaml = (string) file_get_contents(
            \dirname(__DIR__, 6) . '/site-contract/tests/Fixtures/Blueprint/valid/' . $fixture,
        );

        return new SiteManifestParser()->parse($yaml, $fixture);
    }

    private function expected(string $relativePath): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 4) . '/Fixtures/Blueprint/expected/' . $relativePath);
    }
}
