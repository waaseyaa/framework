<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Observability\Replay\RunReplayService;
use Waaseyaa\AI\Observability\Trace;
use Waaseyaa\AI\Pipeline\Pipeline;
use Waaseyaa\AI\Pipeline\PipelineDispatcher;
use Waaseyaa\AI\Pipeline\PipelineQueueMessage;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Queue\QueueInterface;

final class SpyQueue implements QueueInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message): void
    {
        $this->dispatched[] = $message;
    }
}

#[CoversClass(RunReplayService::class)]
final class RunReplayServiceTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private InMemoryStorageDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new InMemoryStorageDriver();
        $eventDispatcher = new EventDispatcher();

        $traceType = new EntityType(
            id: 'trace',
            label: 'Trace',
            class: Trace::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $pipelineType = new EntityType(
            id: 'pipeline',
            label: 'Pipeline',
            class: Pipeline::class,
            keys: ['id' => 'id', 'label' => 'label'],
        );

        $this->entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            null,
            fn(string $entityTypeId) => new EntityRepository(
                $entityTypeId === 'trace' ? $traceType : $pipelineType,
                $this->driver,
                $eventDispatcher,
            ),
        );
    }

    private function saveTrace(string $uuid, string $pipelineLabel): void
    {
        $repo = $this->entityTypeManager->getRepository('trace');
        $trace = new Trace([
            'uuid' => $uuid,
            'label' => $pipelineLabel,
            'status' => 'ok',
            'started_at' => '2026-01-01 10:00:00',
        ]);
        $trace->enforceIsNew();
        $repo->save($trace);
    }

    private function savePipeline(string $id): void
    {
        $repo = $this->entityTypeManager->getRepository('pipeline');
        $pipeline = new Pipeline(['id' => $id, 'label' => $id]);
        $pipeline->enforceIsNew();
        $repo->save($pipeline);
    }

    private function makeQueue(): SpyQueue
    {
        return new SpyQueue();
    }

    #[Test]
    public function replayThrowsWhenTraceNotFound(): void
    {
        $queue = $this->makeQueue();
        $dispatcher = new PipelineDispatcher($queue);
        $service = new RunReplayService($this->entityTypeManager, $dispatcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Trace .* not found/');

        $service->replay('non-existent-uuid');
    }

    #[Test]
    public function replayThrowsWhenPipelineNotFound(): void
    {
        $this->saveTrace('trace-1', 'missing-pipeline');
        // Do NOT save a pipeline entity for 'missing-pipeline'

        $queue = $this->makeQueue();
        $dispatcher = new PipelineDispatcher($queue);
        $service = new RunReplayService($this->entityTypeManager, $dispatcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Pipeline .* not found/');

        $service->replay('trace-1');
    }

    #[Test]
    public function replayReturnsQueuedResultWithNewUuid(): void
    {
        $this->saveTrace('trace-1', 'my-pipeline');
        $this->savePipeline('my-pipeline');

        $queue = $this->makeQueue();
        $dispatcher = new PipelineDispatcher($queue);
        $service = new RunReplayService($this->entityTypeManager, $dispatcher);

        $result = $service->replay('trace-1');

        $this->assertSame('queued', $result->status);
        $this->assertNotEmpty($result->newRunUuid);
        $this->assertNotSame('trace-1', $result->newRunUuid);
        $this->assertNotEmpty($result->startedAt);
    }

    #[Test]
    public function replayDispatchesPipelineToQueue(): void
    {
        $this->saveTrace('trace-1', 'my-pipeline');
        $this->savePipeline('my-pipeline');

        $queue = $this->makeQueue();
        $dispatcher = new PipelineDispatcher($queue);
        $service = new RunReplayService($this->entityTypeManager, $dispatcher);

        $service->replay('trace-1');

        $this->assertCount(1, $queue->dispatched);
        $message = $queue->dispatched[0];
        $this->assertInstanceOf(PipelineQueueMessage::class, $message);
        $this->assertSame('my-pipeline', $message->pipelineId);
        $this->assertSame('trace-1', $message->input['replay_of']);
    }

    #[Test]
    public function replaySavesNewTraceEntityWithQueuedStatus(): void
    {
        $this->saveTrace('trace-1', 'my-pipeline');
        $this->savePipeline('my-pipeline');

        $queue = $this->makeQueue();
        $dispatcher = new PipelineDispatcher($queue);
        $service = new RunReplayService($this->entityTypeManager, $dispatcher);

        $result = $service->replay('trace-1');

        $traceRepo = $this->entityTypeManager->getRepository('trace');
        $newTraces = $traceRepo->findBy(['uuid' => $result->newRunUuid]);
        $this->assertCount(1, $newTraces);
        $newTrace = reset($newTraces);
        $this->assertSame('queued', $newTrace->get('status'));
        $this->assertSame('my-pipeline', $newTrace->get('label'));
    }
}
