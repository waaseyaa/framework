<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Blueprint\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\Emitter\WorkflowDefinitionEmitter;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;
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

    /**
     * `DEFINITION` must actually be a valid `Workflow::__construct()` payload
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
