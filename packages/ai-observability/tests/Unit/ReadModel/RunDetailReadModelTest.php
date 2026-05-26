<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability\Tests\Unit\ReadModel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Observability\ReadModel\RunDetailReadModel;
use Waaseyaa\AI\Observability\Trace;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(RunDetailReadModel::class)]
final class RunDetailReadModelTest extends TestCase
{
    private EntityRepositoryInterface $traceRepo;
    private DBALDatabase $db;

    protected function setUp(): void
    {
        $entityType = new EntityType(
            id: 'trace',
            label: 'Trace',
            class: Trace::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $driver = new InMemoryStorageDriver();
        $this->traceRepo = new EntityRepository($entityType, $driver, new EventDispatcher());

        $this->db = DBALDatabase::createSqlite();
        $schema = new SchemaBuilder($this->db->getConnection());
        $migration = require __DIR__ . '/../../../migrations/2026_04_14_000001_create_trace_span_table.php';
        $migration->up($schema);
    }

    private function saveTrace(string $uuid, string $label = 'pipe', string $startedAt = '2026-01-01 10:00:00', ?string $endedAt = null): void
    {
        $trace = new Trace([
            'uuid' => $uuid,
            'label' => $label,
            'status' => 'ok',
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);
        $trace->enforceIsNew();
        $this->traceRepo->save($trace);
    }

    private function insertSpan(
        string $traceUuid,
        string $spanUuid,
        string $kind = 'tool_call',
        ?string $parentSpanUuid = null,
        array $attributes = [],
    ): void {
        $this->db->insert('trace_span')
            ->values([
                'uuid' => $spanUuid,
                'trace_uuid' => $traceUuid,
                'parent_span_uuid' => $parentSpanUuid,
                'kind' => $kind,
                'name' => $spanUuid . '-name',
                'started_at' => '2026-01-01 10:00:00',
                'ended_at' => null,
                'status' => 'ok',
                'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR),
            ])
            ->execute();
    }

    #[Test]
    public function findByUuidReturnsNullWhenTraceNotFound(): void
    {
        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $result = $model->findByUuid('non-existent-uuid');

        $this->assertNull($result);
    }

    #[Test]
    public function findByUuidReturnsDetailWithEmptySpanTree(): void
    {
        $this->saveTrace('trace-1', 'my-pipeline');

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-1');

        $this->assertNotNull($detail);
        $this->assertSame('trace-1', $detail->header->traceUuid);
        $this->assertSame('my-pipeline', $detail->header->pipeline);
        $this->assertSame([], $detail->spans);
        $this->assertSame(0, $detail->header->spanCount);
    }

    #[Test]
    public function findByUuidBuildsRootSpanList(): void
    {
        $this->saveTrace('trace-1');
        $this->insertSpan('trace-1', 'span-a', 'agent');
        $this->insertSpan('trace-1', 'span-b', 'agent');

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-1');

        $this->assertNotNull($detail);
        $this->assertCount(2, $detail->spans);
        $this->assertSame(2, $detail->header->spanCount);
    }

    #[Test]
    public function findByUuidBuildsChildSpanTree(): void
    {
        $this->saveTrace('trace-1');
        $this->insertSpan('trace-1', 'root', 'agent');
        $this->insertSpan('trace-1', 'child-1', 'tool_call', 'root');
        $this->insertSpan('trace-1', 'child-2', 'llm_call', 'root', ['cost_usd' => 0.01, 'input_tokens' => 50, 'output_tokens' => 100]);

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-1');

        $this->assertNotNull($detail);
        $this->assertCount(1, $detail->spans); // one root
        $root = $detail->spans[0];
        $this->assertSame('root', $root->spanUuid);
        $this->assertCount(2, $root->children);
        $this->assertFalse($root->truncated);

        // Aggregated cost/tokens from llm_call child
        $this->assertEqualsWithDelta(0.01, $detail->header->costUsd, 0.0001);
        $this->assertSame(150, $detail->header->totalTokens);
    }

    #[Test]
    public function findByUuidHandlesMalformedSpanAttributesGracefully(): void
    {
        $this->saveTrace('trace-1');
        $this->db->insert('trace_span')
            ->values([
                'uuid' => 'bad-span',
                'trace_uuid' => 'trace-1',
                'parent_span_uuid' => null,
                'kind' => 'llm_call',
                'name' => 'broken',
                'started_at' => '2026-01-01 10:00:00',
                'ended_at' => null,
                'status' => 'ok',
                'attributes' => '{not json',
            ])
            ->execute();

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-1');

        $this->assertNotNull($detail);
        // Span still appears in tree with empty attributes
        $this->assertCount(1, $detail->spans);
        $this->assertSame([], $detail->spans[0]->attributes);
        // Cost/tokens remain 0 (malformed skipped)
        $this->assertEqualsWithDelta(0.0, $detail->header->costUsd, 0.0001);
    }

    #[Test]
    public function findByUuidOnlyIncludesSpansForRequestedTrace(): void
    {
        $this->saveTrace('trace-1');
        $this->saveTrace('trace-2');
        $this->insertSpan('trace-1', 'span-a');
        $this->insertSpan('trace-2', 'span-b');

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-1');

        $this->assertNotNull($detail);
        $this->assertCount(1, $detail->spans);
        $this->assertSame('span-a', $detail->spans[0]->spanUuid);
    }

    #[Test]
    public function findByUuidMarksTruncatedAtMaxDepth(): void
    {
        $this->saveTrace('trace-deep');

        // Build a 33-level deep chain: root → child-1 → child-2 → ... → child-32
        $this->insertSpan('trace-deep', 'root');
        $prev = 'root';
        for ($i = 1; $i <= 32; $i++) {
            $spanId = 'child-' . $i;
            $this->insertSpan('trace-deep', $spanId, 'tool_call', $prev);
            $prev = $spanId;
        }

        $model = new RunDetailReadModel($this->traceRepo, $this->db);
        $detail = $model->findByUuid('trace-deep');

        $this->assertNotNull($detail);

        // Walk down to the boundary node (depth 32 = child-32)
        $node = $detail->spans[0];
        for ($i = 0; $i < 31; $i++) {
            $this->assertFalse($node->truncated, "Expected not truncated at depth $i");
            $this->assertCount(1, $node->children);
            $node = $node->children[0];
        }
        // At depth 32 (child-32) the node is truncated and has no children
        $this->assertTrue($node->truncated);
        $this->assertSame([], $node->children);
    }
}
