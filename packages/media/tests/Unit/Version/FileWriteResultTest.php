<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\Version\FileWriteResult;

#[CoversClass(FileWriteResult::class)]
final class FileWriteResultTest extends TestCase
{
    #[Test]
    public function it_stores_all_fields(): void
    {
        $sha256 = hash('sha256', 'hello');
        $result = new FileWriteResult(
            blobUri: 'cas://' . $sha256,
            sha256: $sha256,
            sizeBytes: 5,
            mime: 'text/plain',
            dedupHit: false,
        );

        self::assertSame('cas://' . $sha256, $result->blobUri);
        self::assertSame($sha256, $result->sha256);
        self::assertSame(5, $result->sizeBytes);
        self::assertSame('text/plain', $result->mime);
        self::assertFalse($result->dedupHit);
    }

    #[Test]
    public function it_reports_dedup_hit(): void
    {
        $result = new FileWriteResult(
            blobUri: 'cas://abc',
            sha256: 'abc',
            sizeBytes: 3,
            mime: 'text/plain',
            dedupHit: true,
        );

        self::assertTrue($result->dedupHit);
    }
}
