<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\Document\MarkdownDirectorySource;
use Waaseyaa\Search\Document\SearchDocument;

/**
 * @covers \Waaseyaa\Search\Document\MarkdownDirectorySource
 */
#[CoversClass(MarkdownDirectorySource::class)]
final class MarkdownDirectorySourceTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = dirname(__DIR__) . '/fixtures/markdown';
    }

    #[Test]
    public function it_yields_one_document_per_markdown_file_sorted_by_name(): void
    {
        $documents = $this->documents(new MarkdownDirectorySource($this->fixtures, 'spec'));

        $this->assertCount(2, $documents);
        $this->assertContainsOnlyInstancesOf(SearchDocument::class, $documents);
        $this->assertSame(['spec:alpha', 'spec:beta'], array_map(static fn(SearchDocument $d): string => $d->getSearchDocumentId(), $documents));
    }

    #[Test]
    public function it_derives_the_title_from_the_first_h1(): void
    {
        $documents = $this->documents(new MarkdownDirectorySource($this->fixtures, 'spec'));

        $this->assertSame('Alpha Spec', $documents[0]->toSearchDocument()['title']);
        $this->assertStringContainsString('first fixture document', $documents[0]->toSearchDocument()['body']);
    }

    #[Test]
    public function it_falls_back_to_the_file_name_when_there_is_no_h1(): void
    {
        $documents = $this->documents(new MarkdownDirectorySource($this->fixtures, 'spec'));

        $this->assertSame('beta', $documents[1]->toSearchDocument()['title']);
        $this->assertSame('beta', $documents[1]->toSearchMetadata()['source_name']);
    }

    #[Test]
    public function a_missing_directory_yields_nothing(): void
    {
        $this->assertSame([], $this->documents(new MarkdownDirectorySource($this->fixtures . '/does-not-exist')));
    }

    /**
     * @return list<SearchDocument>
     */
    private function documents(MarkdownDirectorySource $source): array
    {
        return iterator_to_array($source->documents(), false);
    }
}
