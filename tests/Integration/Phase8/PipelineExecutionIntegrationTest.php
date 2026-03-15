<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase8;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineDispatcher;
use Waaseyaa\AI\Pipeline\PipelineExecutor;
use Waaseyaa\AI\Pipeline\PipelineQueueMessage;
use Waaseyaa\AI\Pipeline\PipelineStepConfig;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;
use Waaseyaa\Queue\InMemoryQueue;

/**
 * Pipeline execution with step chaining, halt, failure, and queue dispatch.
 *
 * Exercises: waaseyaa/ai-pipeline (Pipeline, PipelineExecutor, PipelineDispatcher,
 * PipelineContext, StepResult, PipelineStepConfig, PipelineQueueMessage)
 * with waaseyaa/queue (InMemoryQueue).
 */
#[CoversNothing]
final class PipelineExecutionIntegrationTest extends TestCase
{
    #[Test]
    public function executePipelineWithThreeStepsChainsOutput(): void
    {
        $pipeline = new Pipeline([
            'id' => 'text_processor',
            'label' => 'Text Processor',
            'description' => 'Processes text through multiple steps.',
            'steps' => [
                ['id' => 'uppercase', 'plugin_id' => 'uppercase', 'label' => 'Uppercase', 'weight' => 0],
                ['id' => 'prefix', 'plugin_id' => 'add_prefix', 'label' => 'Add Prefix', 'weight' => 10, 'configuration' => ['prefix' => '[PROCESSED] ']],
                ['id' => 'count', 'plugin_id' => 'word_count', 'label' => 'Word Count', 'weight' => 20],
            ],
        ]);

        $executor = new PipelineExecutor([
            'uppercase' => new UppercaseStep(),
            'add_prefix' => new AddPrefixStep('[PROCESSED] '),
            'word_count' => new WordCountStep(),
        ]);

        $result = $executor->execute($pipeline, ['text' => 'hello world foo bar']);

        $this->assertTrue($result->success);
        $this->assertSame('Pipeline completed successfully.', $result->message);
        $this->assertCount(3, $result->stepResults);
        $this->assertGreaterThan(0, $result->durationMs);

        // Verify chaining: uppercase -> prefix -> word count.
        $this->assertSame('HELLO WORLD FOO BAR', $result->stepResults[0]->output['text']);
        $this->assertSame('[PROCESSED] HELLO WORLD FOO BAR', $result->stepResults[1]->output['text']);
        $this->assertArrayHasKey('word_count', $result->finalOutput);
        $this->assertSame(5, $result->finalOutput['word_count']);
    }

    #[Test]
    public function pipelineWithFailingStepReturnsPartialResults(): void
    {
        $pipeline = new Pipeline([
            'id' => 'fail_test',
            'label' => 'Fail Test',
            'steps' => [
                ['id' => 'step1', 'plugin_id' => 'uppercase', 'label' => 'Step 1', 'weight' => 0],
                ['id' => 'step2', 'plugin_id' => 'always_fail', 'label' => 'Step 2', 'weight' => 10],
                ['id' => 'step3', 'plugin_id' => 'word_count', 'label' => 'Step 3', 'weight' => 20],
            ],
        ]);

        $executor = new PipelineExecutor([
            'uppercase' => new UppercaseStep(),
            'always_fail' => new AlwaysFailStep(),
            'word_count' => new WordCountStep(),
        ]);

        $result = $executor->execute($pipeline, ['text' => 'test input']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('step2', $result->message);
        // Only 2 steps executed (step1 + step2 which failed), step3 skipped.
        $this->assertCount(2, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertFalse($result->stepResults[1]->success);
    }

    #[Test]
    public function pipelineWithHaltStepStopsExecution(): void
    {
        $pipeline = new Pipeline([
            'id' => 'halt_test',
            'label' => 'Halt Test',
            'steps' => [
                ['id' => 'step1', 'plugin_id' => 'uppercase', 'label' => 'Step 1', 'weight' => 0],
                ['id' => 'step2', 'plugin_id' => 'halt_step', 'label' => 'Step 2', 'weight' => 10],
                ['id' => 'step3', 'plugin_id' => 'word_count', 'label' => 'Step 3', 'weight' => 20],
            ],
        ]);

        $executor = new PipelineExecutor([
            'uppercase' => new UppercaseStep(),
            'halt_step' => new HaltStep(),
            'word_count' => new WordCountStep(),
        ]);

        $result = $executor->execute($pipeline, ['text' => 'halt me']);

        // Pipeline succeeds but halts early.
        $this->assertTrue($result->success);
        $this->assertStringContainsString('halted', strtolower($result->message));
        $this->assertCount(2, $result->stepResults);
        $this->assertTrue($result->stepResults[0]->success);
        $this->assertTrue($result->stepResults[1]->success);
        $this->assertTrue($result->stepResults[1]->stopPipeline);
    }

    #[Test]
    public function dispatchPipelineToInMemoryQueue(): void
    {
        $queue = new InMemoryQueue();
        $dispatcher = new PipelineDispatcher($queue);

        $pipeline = new Pipeline([
            'id' => 'queued_pipeline',
            'label' => 'Queued Pipeline',
            'steps' => [
                ['id' => 'step1', 'plugin_id' => 'uppercase', 'label' => 'Step 1', 'weight' => 0],
            ],
        ]);

        $message = $dispatcher->dispatch($pipeline, ['text' => 'queued input']);

        $this->assertInstanceOf(PipelineQueueMessage::class, $message);
        $this->assertSame('queued_pipeline', $message->pipelineId);
        $this->assertSame(['text' => 'queued input'], $message->input);
        $this->assertGreaterThan(0, $message->createdAt);

        // Verify queue received the message.
        $messages = $queue->getMessages();
        $this->assertCount(1, $messages);
        $this->assertInstanceOf(PipelineQueueMessage::class, $messages[0]);
        $this->assertSame('queued_pipeline', $messages[0]->pipelineId);
    }

    #[Test]
    public function pipelineContextIsSharedAcrossSteps(): void
    {
        $pipeline = new Pipeline([
            'id' => 'context_test',
            'label' => 'Context Test',
            'steps' => [
                ['id' => 'writer', 'plugin_id' => 'context_writer', 'label' => 'Context Writer', 'weight' => 0],
                ['id' => 'reader', 'plugin_id' => 'context_reader', 'label' => 'Context Reader', 'weight' => 10],
            ],
        ]);

        $executor = new PipelineExecutor([
            'context_writer' => new ContextWriterStep(),
            'context_reader' => new ContextReaderStep(),
        ]);

        $result = $executor->execute($pipeline, ['text' => 'context test']);

        $this->assertTrue($result->success);
        $this->assertSame('context_value_set', $result->finalOutput['context_data']);
    }

    #[Test]
    public function pipelineToConfigAndRoundTrip(): void
    {
        $pipeline = new Pipeline([
            'id' => 'config_test',
            'label' => 'Config Test Pipeline',
            'description' => 'A pipeline for testing config export.',
            'steps' => [
                ['id' => 'step1', 'plugin_id' => 'uppercase', 'label' => 'Uppercase', 'weight' => 0],
                ['id' => 'step2', 'plugin_id' => 'add_prefix', 'label' => 'Prefix', 'weight' => 10, 'configuration' => ['prefix' => '>> ']],
            ],
        ]);

        $config = $pipeline->toConfig();

        $this->assertSame('config_test', $config['id']);
        $this->assertSame('Config Test Pipeline', $config['label']);
        $this->assertSame('A pipeline for testing config export.', $config['description']);
        $this->assertCount(2, $config['steps']);
        $this->assertSame('step1', $config['steps'][0]['id']);
        $this->assertSame('uppercase', $config['steps'][0]['plugin_id']);
        $this->assertSame('step2', $config['steps'][1]['id']);
        $this->assertSame(['prefix' => '>> '], $config['steps'][1]['configuration']);

        // Round-trip: rebuild pipeline from config.
        $rebuilt = new Pipeline($config);
        $rebuildConfig = $rebuilt->toConfig();

        $this->assertSame($config['id'], $rebuildConfig['id']);
        $this->assertSame($config['label'], $rebuildConfig['label']);
        $this->assertSame($config['description'], $rebuildConfig['description']);
        $this->assertCount(2, $rebuildConfig['steps']);
    }

    #[Test]
    public function pipelineWithNoStepsReturnInputAsOutput(): void
    {
        $pipeline = new Pipeline([
            'id' => 'empty',
            'label' => 'Empty Pipeline',
            'steps' => [],
        ]);

        $executor = new PipelineExecutor([]);
        $result = $executor->execute($pipeline, ['key' => 'value']);

        $this->assertTrue($result->success);
        $this->assertSame(['key' => 'value'], $result->finalOutput);
        $this->assertEmpty($result->stepResults);
    }

    #[Test]
    public function pipelineStepsExecuteInWeightOrder(): void
    {
        // Steps defined out of order by weight.
        $pipeline = new Pipeline([
            'id' => 'ordering',
            'label' => 'Ordering Test',
            'steps' => [
                ['id' => 'last', 'plugin_id' => 'word_count', 'label' => 'Last', 'weight' => 20],
                ['id' => 'first', 'plugin_id' => 'uppercase', 'label' => 'First', 'weight' => 0],
                ['id' => 'middle', 'plugin_id' => 'add_prefix', 'label' => 'Middle', 'weight' => 10, 'configuration' => ['prefix' => '# ']],
            ],
        ]);

        $executor = new PipelineExecutor([
            'uppercase' => new UppercaseStep(),
            'add_prefix' => new AddPrefixStep('# '),
            'word_count' => new WordCountStep(),
        ]);

        $result = $executor->execute($pipeline, ['text' => 'order test']);

        $this->assertTrue($result->success);
        $this->assertCount(3, $result->stepResults);
        // Step 1 (uppercase): 'ORDER TEST'
        $this->assertSame('ORDER TEST', $result->stepResults[0]->output['text']);
        // Step 2 (prefix): '# ORDER TEST'
        $this->assertSame('# ORDER TEST', $result->stepResults[1]->output['text']);
        // Step 3 (word count)
        $this->assertArrayHasKey('word_count', $result->finalOutput);
        $this->assertGreaterThan(0, $result->finalOutput['word_count']);
    }

    #[Test]
    public function pipelineMissingPluginFailsGracefully(): void
    {
        $pipeline = new Pipeline([
            'id' => 'missing_plugin',
            'label' => 'Missing Plugin',
            'steps' => [
                ['id' => 'step1', 'plugin_id' => 'nonexistent', 'label' => 'Missing', 'weight' => 0],
            ],
        ]);

        $executor = new PipelineExecutor([]);
        $result = $executor->execute($pipeline, ['text' => 'test']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('nonexistent', $result->message);
        $this->assertStringContainsString('not found', $result->message);
    }

    #[Test]
    public function pipelineAddAndRemoveSteps(): void
    {
        $pipeline = new Pipeline([
            'id' => 'dynamic',
            'label' => 'Dynamic Pipeline',
            'steps' => [],
        ]);

        $pipeline->addStep(new PipelineStepConfig(
            id: 'step1',
            pluginId: 'uppercase',
            label: 'Uppercase',
            weight: 0,
        ));

        $this->assertCount(1, $pipeline->getSteps());

        $pipeline->addStep(new PipelineStepConfig(
            id: 'step2',
            pluginId: 'word_count',
            label: 'Word Count',
            weight: 10,
        ));

        $this->assertCount(2, $pipeline->getSteps());

        $pipeline->removeStep('step1');
        $this->assertCount(1, $pipeline->getSteps());
        $this->assertSame('step2', $pipeline->getSteps()[0]->id);
    }
}

// ---- Test Step Implementations ----

/**
 * Converts text to uppercase.
 */
class UppercaseStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        $text = $input['text'] ?? '';
        return StepResult::success(['text' => strtoupper($text)], 'Text uppercased.');
    }

    public function describe(): string
    {
        return 'Converts text to uppercase.';
    }
}

/**
 * Adds a configurable prefix to text.
 */
class AddPrefixStep implements PipelineStepInterface
{
    public function __construct(
        private readonly string $prefix = '[PREFIX] ',
    ) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $text = $input['text'] ?? '';
        return StepResult::success(['text' => $this->prefix . $text], 'Prefix added.');
    }

    public function describe(): string
    {
        return 'Adds a prefix to text.';
    }
}

/**
 * Counts words in text.
 */
class WordCountStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        $text = $input['text'] ?? '';
        $count = str_word_count($text);
        return StepResult::success(
            ['text' => $text, 'word_count' => $count],
            "Counted {$count} words.",
        );
    }

    public function describe(): string
    {
        return 'Counts words in text.';
    }
}

/**
 * Step that always fails.
 */
class AlwaysFailStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        return StepResult::failure('This step always fails.');
    }

    public function describe(): string
    {
        return 'Always fails.';
    }
}

/**
 * Step that halts the pipeline.
 */
class HaltStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        return StepResult::halt('Pipeline halted intentionally.', $input);
    }

    public function describe(): string
    {
        return 'Halts the pipeline.';
    }
}

/**
 * Step that writes to the pipeline context.
 */
class ContextWriterStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        $context->set('shared_key', 'context_value_set');
        return StepResult::success($input, 'Wrote to context.');
    }

    public function describe(): string
    {
        return 'Writes to pipeline context.';
    }
}

/**
 * Step that reads from the pipeline context.
 */
class ContextReaderStep implements PipelineStepInterface
{
    public function process(array $input, PipelineContext $context): StepResult
    {
        $value = $context->get('shared_key', 'not_found');
        return StepResult::success(
            array_merge($input, ['context_data' => $value]),
            'Read from context.',
        );
    }

    public function describe(): string
    {
        return 'Reads from pipeline context.';
    }
}
