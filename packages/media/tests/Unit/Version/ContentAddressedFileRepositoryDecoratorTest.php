<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\InMemoryFileRepository;
use Waaseyaa\Media\Version\ContentAddressedFileRepositoryDecorator;

#[CoversClass(ContentAddressedFileRepositoryDecorator::class)]
final class ContentAddressedFileRepositoryDecoratorTest extends TestCase
{
    private ContentAddressedFileRepositoryDecorator $cas;

    protected function setUp(): void
    {
        $this->cas = new ContentAddressedFileRepositoryDecorator(new InMemoryFileRepository());
    }

    #[Test]
    public function write_stores_blob_and_returns_cas_uri(): void
    {
        $bytes = 'Hello, World!';
        $sha256 = hash('sha256', $bytes);
        $result = $this->cas->write($bytes, 'text/plain');

        self::assertSame('cas://' . $sha256, $result->blobUri);
        self::assertSame($sha256, $result->sha256);
        self::assertSame(strlen($bytes), $result->sizeBytes);
        self::assertSame('text/plain', $result->mime);
        self::assertFalse($result->dedupHit);
    }

    #[Test]
    public function write_deduplicates_identical_content(): void
    {
        $bytes = 'Identical content';
        $result1 = $this->cas->write($bytes, 'text/plain');
        $result2 = $this->cas->write($bytes, 'text/plain');

        self::assertSame($result1->blobUri, $result2->blobUri);
        self::assertSame($result1->sha256, $result2->sha256);
        self::assertFalse($result1->dedupHit);
        self::assertTrue($result2->dedupHit);
    }

    #[Test]
    public function different_content_produces_different_uris(): void
    {
        $result1 = $this->cas->write('content A', 'text/plain');
        $result2 = $this->cas->write('content B', 'text/plain');

        self::assertNotSame($result1->blobUri, $result2->blobUri);
        self::assertNotSame($result1->sha256, $result2->sha256);
    }

    #[Test]
    public function load_returns_stored_file_by_uri(): void
    {
        $result = $this->cas->write('some bytes', 'application/octet-stream');
        $loaded = $this->cas->load($result->blobUri);

        self::assertNotNull($loaded);
        self::assertSame($result->blobUri, $loaded->uri);
    }
}
