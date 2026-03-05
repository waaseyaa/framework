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
                    'body' => 'Water stewardship body.',
                    'relationships' => [['to' => 'story_node', 'type' => 'supports']],
                ],
                [
                    'key' => 'story_node',
                    'title' => 'Story Node',
                    'bundle' => 'story',
                    'workflow_state' => 'review',
                    'body' => 'Story body.',
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
        $this->assertSame('review', $decoded['nodes']['story_node']['workflow_state']);
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
Seasonal water ceremony protocol.

Language Memory
Bundle: story
Workflow: review
Intergenerational language memory notes.
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
}
