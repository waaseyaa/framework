<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\Document\SearchDocument;
use Waaseyaa\Search\SearchIndexableInterface;

/**
 * @covers \Waaseyaa\Search\Document\SearchDocument
 */
#[CoversClass(SearchDocument::class)]
final class SearchDocumentTest extends TestCase
{
    #[Test]
    public function it_is_a_search_indexable(): void
    {
        $this->assertInstanceOf(SearchIndexableInterface::class, new SearchDocument('spec:entity-system', 'Entity System', 'body'));
    }

    #[Test]
    public function it_exposes_id_title_and_body(): void
    {
        $document = new SearchDocument('spec:entity-system', 'Entity System', 'An entity type defines fields.');

        $this->assertSame('spec:entity-system', $document->getSearchDocumentId());
        $this->assertSame(['title' => 'Entity System', 'body' => 'An entity type defines fields.'], $document->toSearchDocument());
    }

    #[Test]
    public function entity_type_defaults_to_document(): void
    {
        $document = new SearchDocument('spec:x', 'X', 'y');

        $this->assertSame('document', $document->toSearchMetadata()['entity_type']);
    }

    #[Test]
    public function caller_metadata_overrides_the_default_entity_type(): void
    {
        $document = new SearchDocument('spec:x', 'X', 'y', ['entity_type' => 'spec', 'source_name' => 'x']);

        $metadata = $document->toSearchMetadata();
        $this->assertSame('spec', $metadata['entity_type']);
        $this->assertSame('x', $metadata['source_name']);
    }
}
