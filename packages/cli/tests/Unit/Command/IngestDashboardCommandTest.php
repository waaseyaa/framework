<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\IngestDashboardHandler;
use Waaseyaa\CLI\Provider\IngestSearchSemanticServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(IngestDashboardHandler::class)]
final class IngestDashboardCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_ingest_dashboard_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($this->tempDir);
    }

    private function makeTester(): CliTester
    {
        $provider = new IngestSearchSemanticServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'ingest:dashboard') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition, 'ingest:dashboard command definition must exist');

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                return new IngestDashboardHandler();
            }

            public function has(string $id): bool
            {
                return $id === IngestDashboardHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }

    #[Test]
    public function it_aggregates_runs_into_deterministic_json_dashboard_payload(): void
    {
        $blocked = $this->tempDir . '/blocked.json';
        $review = $this->tempDir . '/review.json';
        $ready = $this->tempDir . '/ready.json';

        file_put_contents($blocked, json_encode($this->artifact(
            batchId: 'batch_blocked',
            policy: 'atomic_fail_fast',
            errorCount: 2,
            inferredRelationships: 0,
            nodes: [
                'a' => ['workflow_state' => 'draft', 'status' => 0],
            ],
            diagnostics: [
                'schema' => [['code' => 'schema.invalid_items_type']],
            ],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        file_put_contents($review, json_encode($this->artifact(
            batchId: 'batch_review',
            policy: 'atomic_fail_fast',
            errorCount: 0,
            inferredRelationships: 1,
            nodes: [
                'b' => ['workflow_state' => 'published', 'status' => 1],
            ],
            diagnostics: [
                'inference' => [['code' => 'inference.relationship_inferred']],
                'refresh' => [['code' => 'refresh.relationship_change']],
                'refresh_summary' => ['needs_refresh' => true, 'primary_category' => 'relationship_change'],
            ],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        file_put_contents($ready, json_encode($this->artifact(
            batchId: 'batch_ready',
            policy: 'atomic_fail_fast',
            errorCount: 0,
            inferredRelationships: 0,
            nodes: [
                'c' => ['workflow_state' => 'published', 'status' => 1],
            ],
            diagnostics: [
                'refresh_summary' => ['needs_refresh' => false, 'primary_category' => null],
            ],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $tester = $this->makeTester();
        $tester->executeMap([
            '--input' => [$review, $blocked, $ready],
            '--json' => true,
        ]);

        $this->assertSame(0, $tester->getExitCode());
        $decoded = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(3, $decoded['meta']['run_count']);
        $this->assertSame([
            'blocked' => 1,
            'review' => 1,
            'ready' => 1,
        ], $decoded['summary']['queue_status_counts']);
        $this->assertSame(1, $decoded['summary']['refresh_required_count']);
        $this->assertSame(1, $decoded['summary']['inference_review_pending_total']);
        $this->assertSame(['draft' => 1, 'published' => 2], $decoded['summary']['workflow_state_totals']);
        $this->assertSame('batch_blocked', $decoded['runs'][0]['batch_id']);
    }

    #[Test]
    public function it_renders_text_dashboard_from_glob_and_writes_output_file(): void
    {
        $artifactPath = $this->tempDir . '/single.json';
        file_put_contents($artifactPath, json_encode($this->artifact(
            batchId: 'batch_single',
            policy: 'validate_only',
            errorCount: 0,
            inferredRelationships: 0,
            nodes: [
                'x' => ['workflow_state' => 'draft', 'status' => 0],
            ],
            diagnostics: [],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $outputPath = $this->tempDir . '/dashboard.json';

        $tester = $this->makeTester();
        $tester->executeMap([
            '--glob' => $this->tempDir . '/*.json',
            '--output' => $outputPath,
        ]);

        $this->assertSame(0, $tester->getExitCode());
        $display = $tester->getStdout();
        $this->assertStringContainsString('INGEST EDITORIAL DASHBOARD', $display);
        $this->assertStringContainsString('Queue: blocked=0 review=1 ready=0', $display);
        $this->assertFileExists($outputPath);
    }

    #[Test]
    public function it_fails_with_invalid_status_when_no_inputs_exist(): void
    {
        $tester = $this->makeTester();
        $tester->execute([]);

        $this->assertSame(2, $tester->getExitCode());
        $this->assertStringContainsString('No ingest artifacts found', $tester->getStderr());
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private function artifact(
        string $batchId,
        string $policy,
        int $errorCount,
        int $inferredRelationships,
        array $nodes,
        array $diagnostics,
    ): array {
        return [
            'meta' => [
                'batch_id' => $batchId,
                'policy' => $policy,
                'source' => 'dataset://test',
                'error_count' => $errorCount,
                'node_count' => count($nodes),
                'relationship_count' => 0,
                'inferred_relationship_count' => $inferredRelationships,
            ],
            'nodes' => $nodes,
            'relationships' => [],
            'diagnostics' => array_merge([
                'schema' => [],
                'validation' => [],
                'inference' => [],
                'refresh' => [],
                'errors' => [],
                'warnings' => [],
                'refresh_summary' => ['needs_refresh' => false, 'primary_category' => null],
            ], $diagnostics),
        ];
    }
}
