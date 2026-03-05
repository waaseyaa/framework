<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\IngestRunCommand;

#[CoversClass(IngestRunCommand::class)]
final class IngestRunCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_ingest_' . uniqid();
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

    #[Test]
    public function it_maps_structured_input_with_relationships_deterministically(): void
    {
        $inputPath = $this->tempDir . '/structured.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'water_anchor',
                    'title' => 'Water Anchor',
                    'bundle' => 'teaching',
                    'workflow_state' => 'published',
                    'body' => 'Water stewardship teachings guide seasonal practice across communities.',
                    'relationships' => [['to' => 'story_node', 'type' => 'supports']],
                ],
                [
                    'key' => 'story_node',
                    'title' => 'Story Node',
                    'bundle' => 'story',
                    'workflow_state' => 'published',
                    'body' => 'Story teachings preserve memory across generations through ceremony.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-structured.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--source' => 'dataset://test',
            '--timestamp' => '1735689600',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('structured', $decoded['meta']['format']);
        $this->assertSame(2, $decoded['meta']['node_count']);
        $this->assertSame(1, $decoded['meta']['relationship_count']);
        $this->assertSame(0, $decoded['meta']['error_count']);
        $this->assertSame(1, $decoded['nodes']['water_anchor']['status']);
        $this->assertSame('published', $decoded['nodes']['story_node']['workflow_state']);
        $this->assertSame('water_anchor_to_story_node_supports', $decoded['relationships'][0]['key']);
    }

    #[Test]
    public function it_maps_unstructured_blocks_and_parses_inline_relationships(): void
    {
        $inputPath = $this->tempDir . '/unstructured.txt';
        file_put_contents($inputPath, <<<TXT
Water Ceremony
Bundle: teaching
Workflow: published
Relates: language_memory supports
Seasonal water ceremony protocol preserves intergenerational knowledge practices.

Language Memory
Bundle: story
Workflow: published
Intergenerational language memory notes preserve oral teaching continuity.
TXT);

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-unstructured.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'unstructured',
            '--source' => 'manual://notes',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('unstructured', $decoded['meta']['format']);
        $this->assertArrayHasKey('water_ceremony', $decoded['nodes']);
        $this->assertArrayHasKey('language_memory', $decoded['nodes']);
        $this->assertSame('published', $decoded['nodes']['water_ceremony']['workflow_state']);
        $this->assertSame('water_ceremony_to_language_memory_supports', $decoded['relationships'][0]['key']);
    }

    #[Test]
    public function it_reports_explicit_diagnostics_for_invalid_mapping_targets(): void
    {
        $inputPath = $this->tempDir . '/invalid.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'anchor',
                    'title' => 'Anchor',
                    'relationships' => [['to' => 'missing_target', 'type' => 'supports']],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-invalid.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--source' => 'dataset://invalid',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertGreaterThan(0, $decoded['meta']['error_count']);
        $this->assertStringContainsString('Relationship target key missing', $decoded['diagnostics']['errors'][0]);
    }

    #[Test]
    public function it_emits_contract_shaped_schema_diagnostics_for_unknown_batch_scheme(): void
    {
        $inputPath = $this->tempDir . '/unknown-scheme.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'anchor',
                    'title' => 'Anchor',
                    'source_uri' => 'source://anchor',
                    'ingested_at' => 1735689600,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-unknown-scheme.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--policy' => 'validate_only',
            '--source' => 'legacy://source-set',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $decoded['meta']['schema_error_count']);
        $this->assertSame([], $decoded['nodes']);
        $this->assertSame([], $decoded['relationships']);
        $this->assertSame([
            'error_count' => 1,
            'warning_count' => 0,
            'codes' => ['schema.unknown_source_set_scheme'],
        ], $decoded['diagnostics']['schema_summary']);

        $diagnostic = $decoded['diagnostics']['schema'][0];
        $this->assertSame('schema.unknown_source_set_scheme', $diagnostic['code']);
        $this->assertSame('/source_set_uri', $diagnostic['location']);
        $this->assertNull($diagnostic['item_index']);
        $this->assertStringContainsString('Unknown source_set_uri scheme', (string) $diagnostic['message']);
        $this->assertSame(['value', 'expected', 'allowed_schemes'], array_keys($diagnostic['context']));
    }

    #[Test]
    public function it_emits_duplicate_source_uri_diagnostics_with_item_location(): void
    {
        $inputPath = $this->tempDir . '/duplicate-source-uri.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'anchor_a',
                    'title' => 'Anchor A',
                    'source_uri' => 'source://dup',
                    'ingested_at' => 1735689600,
                ],
                [
                    'key' => 'anchor_b',
                    'title' => 'Anchor B',
                    'source_uri' => ' source://dup ',
                    'ingested_at' => 1735689601,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-duplicate-source-uri.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--policy' => 'validate_only',
            '--source' => 'dataset://set',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);
        $schema = $decoded['diagnostics']['schema'];

        $duplicate = array_values(array_filter(
            $schema,
            static fn(array $row): bool => (string) ($row['code'] ?? '') === 'schema.duplicate_source_uri',
        ));
        $this->assertCount(1, $duplicate);
        $this->assertSame('/items/1/source_uri', $duplicate[0]['location']);
        $this->assertSame(1, $duplicate[0]['item_index']);
    }

    #[Test]
    public function validate_only_policy_emits_validation_gate_diagnostics_without_mapping(): void
    {
        $inputPath = $this->tempDir . '/validate-only-validation-gates.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'published_short',
                    'title' => 'Published Short',
                    'workflow_state' => 'published',
                    'source_uri' => 'item://published_short',
                    'ingested_at' => 1735689600,
                    'body' => 'tiny',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-validate-only-validation-gates.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--policy' => 'validate_only',
            '--source' => 'dataset://set',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $decoded['nodes']);
        $this->assertSame([], $decoded['relationships']);
        $this->assertSame(1, $decoded['meta']['validation_error_count']);
        $this->assertSame([
            'error_count' => 1,
            'warning_count' => 0,
            'categories' => ['semantic'],
            'codes' => ['validation.semantic.insufficient_publishable_tokens'],
        ], $decoded['diagnostics']['validation_summary']);
    }

    #[Test]
    public function atomic_policy_blocks_publishable_output_when_validation_gates_fail(): void
    {
        $inputPath = $this->tempDir . '/atomic-validation-gate-failure.txt';
        file_put_contents($inputPath, <<<TXT
Public Story
Bundle: story
Workflow: published
Relates: draft_story supports
tiny

Draft Story
Bundle: teaching
Workflow: draft
Has enough words here to avoid semantic gate issues.
TXT);

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-atomic-validation-gate-failure.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'unstructured',
            '--policy' => 'atomic_fail_fast',
            '--source' => 'manual://notes',
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $decoded['nodes']);
        $this->assertSame([], $decoded['relationships']);
        $codes = array_values(array_map(
            static fn(array $row): string => (string) ($row['code'] ?? ''),
            $decoded['diagnostics']['validation'],
        ));
        $this->assertContains('validation.semantic.insufficient_publishable_tokens', $codes);
        $this->assertContains('validation.visibility.relationship_requires_public_endpoints', $codes);
    }

    #[Test]
    public function it_infers_review_safe_relationships_when_enabled(): void
    {
        $inputPath = $this->tempDir . '/inference-enabled.json';
        file_put_contents($inputPath, json_encode([
            'items' => [
                [
                    'key' => 'water_story',
                    'title' => 'Water Story',
                    'workflow_state' => 'published',
                    'source_uri' => 'item://water_story',
                    'ingested_at' => 1735689600,
                    'body' => 'Water stewardship knowledge supports seasonal ceremony and memory continuity.',
                ],
                [
                    'key' => 'seasonal_memory',
                    'title' => 'Seasonal Memory',
                    'workflow_state' => 'published',
                    'source_uri' => 'item://seasonal_memory',
                    'ingested_at' => 1735689601,
                    'body' => 'Seasonal ceremony memory teachings support community stewardship and continuity.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $app = new Application();
        $app->add(new IngestRunCommand());
        $tester = new CommandTester($app->find('ingest:run'));
        $mappedPath = $this->tempDir . '/mapped-inference-enabled.json';
        $tester->execute([
            '--input' => $inputPath,
            '--format' => 'structured',
            '--source' => 'dataset://inference',
            '--infer-relationships' => true,
            '--output' => $mappedPath,
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $decoded = json_decode((string) file_get_contents($mappedPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($decoded['meta']['inference_enabled']);
        $this->assertSame(1, $decoded['meta']['inferred_relationship_count']);
        $this->assertCount(1, $decoded['diagnostics']['inference']);
        $this->assertSame('inference.relationship_inferred', $decoded['diagnostics']['inference'][0]['code']);
        $this->assertSame('needs_review', $decoded['relationships'][0]['inference_review_state']);
        $this->assertSame(0, $decoded['relationships'][0]['status']);
    }
}
