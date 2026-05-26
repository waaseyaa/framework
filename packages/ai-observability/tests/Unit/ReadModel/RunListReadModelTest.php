<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\ReadModel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Observability\ReadModel\RunListReadModel;
use Waaseyaa\AI\Observability\Trace;
use Waaseyaa\Api\AiObservability\Runs\RunListFilter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(RunListReadModel::class)]
final class RunListReadModelTest extends TestCase
{
    private EntityRepositoryInterface $traceRepo;
    private DBALDatabase $db;

    protected function setUp(): void
    {
        // Build in-memory trace repository
        $entityType = new EntityType(
            id: 'trace',
            label: 'Trace',
            class: Trace::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $driver = new InMemoryStorageDriver();
        $this->traceRepo = new EntityRepository($entityType, $driver, new EventDispatcher());

        // Build in-memory SQLite DB with trace_span table
        $this->db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($this->db->getConnection());
        $migration = require __DIR__ . '/../../../migrations/2026_04_14_000001_create_trace_span_table.php';
        $migration->up($schema);
    }

    private function saveTrace(string $uuid, string $label, string $startedAt, ?string $endedAt = null, string $item_status = 'ok'): void
    {
        $trace = new Trace([
            'uuid' => $uuid,
            'label' => $label,
            'status' => $item_status,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);
        $trace->enforceIsNew();
        $this->traceRepo->save($trace);
    }

    private function insertSpan(string $traceUuid, string $kind, float $costUsd = 0.0, int $inputTokens = 0, int $outputTokens = 0): void
    {
        $spanUuid = 'span-' . uniqid('', true);
        $attrs = json_encode(['cost_usd' => $costUsd, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens], JSON_THROW_ON_ERROR);
        $this->db->insert('trace_span')
            ->values([
                'uuid' => $spanUuid,
                'trace_uuid' => $traceUuid,
                'parent_span_uuid' => null,
                'kind' => $kind,
                'name' => 'test-span',
                'started_at' => '2026-01-01 10:00:00',
                'ended_at' => null,
                'status' => 'ok',
                'attributes' => $attrs,
            ])
            ->execute();
    }

    #[Test]
    public function recentRunsReturnsEmptyPageWhenNoTraces(): void
    {
        $model = new RunListReadModel($this->traceRepo, $this->db);
        $page = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 25);

        $this->assertSame(0, $page->total);
        $this->assertSame([], $page->rows);
        $this->assertSame(1, $page->page);
        $this->assertSame(25, $page->perPage);
    }

    #[Test]
    public function recentRunsReturnsRowsWithAggregatedSpanData(): void
    {
        $this->saveTrace('trace-1', 'my-pipeline', '2026-01-01 10:00:00', '2026-01-01 10:00:05');
        $this->insertSpan('trace-1', 'llm_call', 0.042, 100, 200);
        $this->insertSpan('trace-1', 'tool_call');

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $page = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 25);

        $this->assertSame(1, $page->total);
        $row = $page->rows[0];
        $this->assertSame('trace-1', $row->traceUuid);
        $this->assertSame('my-pipeline', $row->pipeline);
        $this->assertEqualsWithDelta(0.042, $row->costUsd, 0.0001);
        $this->assertSame(300, $row->totalTokens);
        $this->assertSame(2, $row->spanCount);
    }

    #[Test]
    public function recentRunsFiltersOnPipeline(): void
    {
        $this->saveTrace('trace-1', 'pipe-a', '2026-01-01 10:00:00');
        $this->saveTrace('trace-2', 'pipe-b', '2026-01-01 11:00:00');

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $filter = new RunListFilter(pipeline: 'pipe-a', status: null, from: null, to: null);
        $page = $model->recentRuns($filter, 1, 25);

        $this->assertSame(1, $page->total);
        $this->assertSame('pipe-a', $page->rows[0]->pipeline);
    }

    #[Test]
    public function recentRunsFiltersOnStatus(): void
    {
        $this->saveTrace('trace-1', 'pipe', '2026-01-01 10:00:00', null, 'ok');
        $this->saveTrace('trace-2', 'pipe', '2026-01-01 11:00:00', null, 'error');

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $filter = new RunListFilter(pipeline: null, status: 'error', from: null, to: null);
        $page = $model->recentRuns($filter, 1, 25);

        $this->assertSame(1, $page->total);
        $this->assertSame('error', $page->rows[0]->status);
    }

    #[Test]
    public function recentRunsSortsNewestFirst(): void
    {
        $this->saveTrace('trace-old', 'pipe', '2026-01-01 08:00:00');
        $this->saveTrace('trace-new', 'pipe', '2026-01-01 12:00:00');

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $page = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 25);

        $this->assertSame('trace-new', $page->rows[0]->traceUuid);
        $this->assertSame('trace-old', $page->rows[1]->traceUuid);
    }

    #[Test]
    public function recentRunsPaginatesCorrectly(): void
    {
        $this->saveTrace('trace-1', 'pipe', '2026-01-01 10:00:00');
        $this->saveTrace('trace-2', 'pipe', '2026-01-01 11:00:00');
        $this->saveTrace('trace-3', 'pipe', '2026-01-01 12:00:00');

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $page = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 2);

        $this->assertSame(3, $page->total);
        $this->assertCount(2, $page->rows);
        $this->assertSame(1, $page->page);
        $this->assertSame(2, $page->perPage);

        $page2 = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 2, 2);
        $this->assertCount(1, $page2->rows);
    }

    #[Test]
    public function recentRunsHandlesMalformedSpanAttributesGracefully(): void
    {
        $this->saveTrace('trace-1', 'pipe', '2026-01-01 10:00:00');
        // Insert a span with malformed JSON
        $this->db->insert('trace_span')
            ->values([
                'uuid' => 'span-bad',
                'trace_uuid' => 'trace-1',
                'parent_span_uuid' => null,
                'kind' => 'llm_call',
                'name' => 'broken',
                'started_at' => '2026-01-01 10:00:00',
                'ended_at' => null,
                'status' => 'ok',
                'attributes' => '{not valid json',
            ])
            ->execute();

        $model = new RunListReadModel($this->traceRepo, $this->db);
        $page = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 25);

        // Should not throw; cost/tokens should be 0 for malformed span
        $this->assertSame(1, $page->total);
        $this->assertEqualsWithDelta(0.0, $page->rows[0]->costUsd, 0.0001);
    }

    #[Test]
    public function recentRunsClampsPerPageToMin1AndMax100(): void
    {
        $this->saveTrace('trace-1', 'pipe', '2026-01-01 10:00:00');

        $model = new RunListReadModel($this->traceRepo, $this->db);

        $pageMin = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 0);
        $this->assertSame(1, $pageMin->perPage);

        $pageMax = $model->recentRuns(new RunListFilter(pipeline: null, status: null, from: null, to: null), 1, 9999);
        $this->assertSame(100, $pageMax->perPage);
    }
}
