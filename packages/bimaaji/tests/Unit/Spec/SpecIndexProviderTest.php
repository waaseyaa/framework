<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface;
use Waaseyaa\Bimaaji\Spec\SpecIndexProvider;

#[CoversClass(SpecIndexProvider::class)]
final class SpecIndexProviderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($this->tempDir . '/specs', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function it_implements_graph_section_provider_interface(): void
    {
        $provider = new SpecIndexProvider($this->tempDir . '/specs');

        $this->assertInstanceOf(GraphSectionProviderInterface::class, $provider);
    }

    #[Test]
    public function get_key_returns_spec_index(): void
    {
        $provider = new SpecIndexProvider($this->tempDir . '/specs');

        $this->assertSame('spec_index', $provider->getKey());
    }

    #[Test]
    public function provide_returns_graph_section_with_spec_entries(): void
    {
        $specsDir = $this->tempDir . '/specs';
        file_put_contents($specsDir . '/entity-system.md', '# Entity System');
        file_put_contents($specsDir . '/access-control.md', '# Access Control');

        $provider = new SpecIndexProvider($specsDir);
        $section = $provider->provide();

        $this->assertInstanceOf(GraphSection::class, $section);
        $this->assertSame('spec_index', $section->key);
        $this->assertArrayHasKey('entity-system', $section->data);
        $this->assertArrayHasKey('access-control', $section->data);

        $this->assertSame('entity-system', $section->data['entity-system']['name']);
        $this->assertSame($specsDir . '/entity-system.md', $section->data['entity-system']['path']);

        $this->assertSame('access-control', $section->data['access-control']['name']);
        $this->assertSame($specsDir . '/access-control.md', $section->data['access-control']['path']);
    }

    #[Test]
    public function provide_returns_empty_data_for_empty_directory(): void
    {
        $provider = new SpecIndexProvider($this->tempDir . '/specs');
        $section = $provider->provide();

        $this->assertSame([], $section->data);
    }

    #[Test]
    public function provide_returns_empty_data_when_filesystem_indexing_is_disabled(): void
    {
        $provider = new SpecIndexProvider(null);

        $this->assertSame([], $provider->provide()->data);
    }

    #[Test]
    public function provide_skips_nonexistent_directory_silently(): void
    {
        $provider = new SpecIndexProvider($this->tempDir . '/nonexistent');
        $section = $provider->provide();

        $this->assertSame('spec_index', $section->key);
        $this->assertSame([], $section->data);
    }

    #[Test]
    public function provide_includes_additional_paths(): void
    {
        $mainDir = $this->tempDir . '/specs';
        $extraDir = $this->tempDir . '/extra-specs';
        mkdir($extraDir, 0777, true);

        file_put_contents($mainDir . '/entity-system.md', '# Entity System');
        file_put_contents($extraDir . '/custom-spec.md', '# Custom Spec');

        $provider = new SpecIndexProvider($mainDir, [$extraDir]);
        $section = $provider->provide();

        $this->assertArrayHasKey('entity-system', $section->data);
        $this->assertArrayHasKey('custom-spec', $section->data);
        $this->assertSame($extraDir . '/custom-spec.md', $section->data['custom-spec']['path']);
    }

    #[Test]
    public function provide_skips_nonexistent_additional_paths(): void
    {
        $mainDir = $this->tempDir . '/specs';
        file_put_contents($mainDir . '/workflow.md', '# Workflow');

        $provider = new SpecIndexProvider($mainDir, [$this->tempDir . '/ghost']);
        $section = $provider->provide();

        $this->assertCount(1, $section->data);
        $this->assertArrayHasKey('workflow', $section->data);
    }

    #[Test]
    public function provide_ignores_non_md_files(): void
    {
        $specsDir = $this->tempDir . '/specs';
        file_put_contents($specsDir . '/valid-spec.md', '# Valid');
        file_put_contents($specsDir . '/notes.txt', 'not a spec');
        file_put_contents($specsDir . '/data.json', '{}');

        $provider = new SpecIndexProvider($specsDir);
        $section = $provider->provide();

        $this->assertCount(1, $section->data);
        $this->assertArrayHasKey('valid-spec', $section->data);
    }

    #[Test]
    public function provide_does_not_read_file_contents(): void
    {
        $specsDir = $this->tempDir . '/specs';
        file_put_contents($specsDir . '/big-spec.md', str_repeat('x', 10000));

        $provider = new SpecIndexProvider($specsDir);
        $section = $provider->provide();

        // Data should only contain path and name, not contents
        $entry = $section->data['big-spec'];
        $this->assertSame(['name', 'path'], array_keys($entry));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
