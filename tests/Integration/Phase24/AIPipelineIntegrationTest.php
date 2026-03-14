<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase24;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineExecutor;
use Waaseyaa\AI\Pipeline\PipelineStepConfig;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;
use Waaseyaa\AI\Schema\EntityJsonSchemaGenerator;
use Waaseyaa\AI\Schema\Mcp\McpToolGenerator;
use Waaseyaa\AI\Schema\SchemaRegistry;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Integration tests for the AI pipeline execution and schema generation subsystems.
 *
 * Phase 24 — covers PipelineExecutor step chaining and failure propagation,
 * and SchemaRegistry JSON Schema generation via real entity types.
 */
#[CoversNothing]
final class AIPipelineIntegrationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // PipelineExecutor tests
    // -------------------------------------------------------------------------

    #[Test]
    public function executor_runs_steps_in_order_and_chains_output(): void
    {
        $log = [];

        $stepA = new class ($log) implements PipelineStepInterface {
            public function __construct(private array &$log) {}

            public function process(array $input, PipelineContext $context): StepResult
            {
                $this->log[] = 'step-a';

                return StepResult::success(['value' => ($input['value'] ?? 0) + 10]);
            }

            public function describe(): string
            {
                return 'Adds 10';
            }
        };

        $stepB = new class ($log) implements PipelineStepInterface {
            public function __construct(private array &$log) {}

            public function process(array $input, PipelineContext $context): StepResult
            {
                $this->log[] = 'step-b';

                return StepResult::success(['value' => ($input['value'] ?? 0) + 5]);
            }

            public function describe(): string
            {
                return 'Adds 5';
            }
        };

        $executor = new PipelineExecutor(stepPlugins: [
            'plugin.add_ten' => $stepA,
            'plugin.add_five' => $stepB,
        ]);

        $pipeline = new Pipeline(['id' => 'test-chain', 'label' => 'Chain Test']);
        $pipeline->addStep(new PipelineStepConfig(id: 'step-a', pluginId: 'plugin.add_ten', weight: 1));
        $pipeline->addStep(new PipelineStepConfig(id: 'step-b', pluginId: 'plugin.add_five', weight: 2));

        $result = $executor->execute($pipeline, ['value' => 0]);

        $this->assertTrue($result->success);
        $this->assertSame(['step-a', 'step-b'], $log);
        $this->assertSame(['value' => 15], $result->finalOutput);
        $this->assertCount(2, $result->stepResults);
    }

    #[Test]
    public function executor_stops_and_reports_failure_when_step_fails(): void
    {
        $sentinel = new \stdClass();
        $sentinel->secondStepCalled = false;

        $failingStep = new class implements PipelineStepInterface {
            public function process(array $input, PipelineContext $context): StepResult
            {
                return StepResult::failure('Something went wrong');
            }

            public function describe(): string
            {
                return 'Always fails';
            }
        };

        $secondStep = new class ($sentinel) implements PipelineStepInterface {
            public function __construct(private readonly \stdClass $sentinel) {}

            public function process(array $input, PipelineContext $context): StepResult
            {
                $this->sentinel->secondStepCalled = true;

                return StepResult::success();
            }

            public function describe(): string
            {
                return 'Should not run';
            }
        };

        $executor = new PipelineExecutor(stepPlugins: [
            'plugin.fail' => $failingStep,
            'plugin.second' => $secondStep,
        ]);

        $pipeline = new Pipeline(['id' => 'test-fail', 'label' => 'Fail Test']);
        $pipeline->addStep(new PipelineStepConfig(id: 'step-fail', pluginId: 'plugin.fail', weight: 1));
        $pipeline->addStep(new PipelineStepConfig(id: 'step-second', pluginId: 'plugin.second', weight: 2));

        $result = $executor->execute($pipeline, []);

        $this->assertFalse($result->success);
        $this->assertFalse($sentinel->secondStepCalled, 'Second step must not run after a failure');
        $this->assertCount(1, $result->stepResults);
        $this->assertStringContainsString('step-fail', $result->message);
        $this->assertStringContainsString('Something went wrong', $result->message);
    }

    #[Test]
    public function executor_fails_immediately_when_plugin_is_missing(): void
    {
        $executor = new PipelineExecutor(stepPlugins: []);

        $pipeline = new Pipeline(['id' => 'test-missing', 'label' => 'Missing Plugin Test']);
        $pipeline->addStep(new PipelineStepConfig(
            id: 'step-ghost',
            pluginId: 'plugin.does_not_exist',
            weight: 1,
        ));

        $result = $executor->execute($pipeline, []);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('plugin.does_not_exist', $result->message);
    }

    #[Test]
    public function executor_returns_success_for_empty_pipeline(): void
    {
        $executor = new PipelineExecutor(stepPlugins: []);

        $pipeline = new Pipeline(['id' => 'test-empty', 'label' => 'Empty Pipeline']);

        $result = $executor->execute($pipeline, ['seed' => 42]);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->stepResults);
        $this->assertSame(['seed' => 42], $result->finalOutput);
    }

    // -------------------------------------------------------------------------
    // SchemaRegistry tests
    // -------------------------------------------------------------------------

    #[Test]
    public function schema_registry_returns_valid_json_schema_for_entity_type(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: \stdClass::class,
            keys: ['id' => 'nid', 'label' => 'title'],
        ));

        $generator = new EntityJsonSchemaGenerator($manager);
        $toolGenerator = new McpToolGenerator($manager);
        $registry = new SchemaRegistry($generator, $toolGenerator);

        $schema = $registry->getSchema('article');

        $this->assertArrayHasKey('$schema', $schema);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertArrayHasKey('title', $schema);
        $this->assertSame('Article', $schema['title']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('nid', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    #[Test]
    public function schema_registry_returns_all_schemas(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'page',
            label: 'Page',
            class: \stdClass::class,
            keys: ['id' => 'nid', 'label' => 'title'],
        ));
        $manager->registerEntityType(new EntityType(
            id: 'tag',
            label: 'Tag',
            class: \stdClass::class,
            keys: ['id' => 'tid', 'label' => 'name'],
        ));

        $generator = new EntityJsonSchemaGenerator($manager);
        $toolGenerator = new McpToolGenerator($manager);
        $registry = new SchemaRegistry($generator, $toolGenerator);

        $all = $registry->getAllSchemas();

        $this->assertArrayHasKey('page', $all);
        $this->assertArrayHasKey('tag', $all);
        $this->assertSame('Page', $all['page']['title']);
        $this->assertSame('Tag', $all['tag']['title']);
    }
}
