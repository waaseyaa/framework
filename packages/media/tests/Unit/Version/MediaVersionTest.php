<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\Version\MediaVersion;

#[CoversClass(MediaVersion::class)]
final class MediaVersionTest extends TestCase
{
    #[Test]
    public function it_constructs_with_defaults(): void
    {
        $version = new MediaVersion();

        self::assertSame('media_version', $version->getEntityTypeId());
        self::assertSame('', $version->mediaUuid());
        self::assertSame(0, $version->vid());
        self::assertSame('', $version->blobUri());
        self::assertSame('', $version->mime());
        self::assertSame(0, $version->size());
        self::assertSame('', $version->sha256());
        self::assertSame(0, $version->createdAt());
        self::assertSame(0, $version->createdBy());
    }

    #[Test]
    public function it_reads_field_values_from_constructor_array(): void
    {
        $sha256 = str_repeat('a', 64);
        $version = new MediaVersion([
            'id'         => 42,
            'uuid'       => 'test-uuid-1234',
            'media_uuid' => 'parent-media-uuid',
            'vid'        => 3,
            'blob_uri'   => 'cas://' . $sha256,
            'mime'       => 'image/png',
            'size'       => 1024,
            'sha256'     => $sha256,
            'created_at' => 1716652800,
            'created_by' => 7,
        ]);

        self::assertSame('parent-media-uuid', $version->mediaUuid());
        self::assertSame(3, $version->vid());
        self::assertSame('cas://' . $sha256, $version->blobUri());
        self::assertSame('image/png', $version->mime());
        self::assertSame(1024, $version->size());
        self::assertSame($sha256, $version->sha256());
        self::assertSame(1716652800, $version->createdAt());
        self::assertSame(7, $version->createdBy());
    }

    #[Test]
    public function it_is_new_by_default(): void
    {
        $version = new MediaVersion(['media_uuid' => 'abc']);
        self::assertTrue($version->isNew());
    }

    #[Test]
    public function enforce_is_new_false_marks_as_not_new(): void
    {
        $version = new MediaVersion(['id' => 1, 'media_uuid' => 'abc']);
        $version->enforceIsNew(false);
        self::assertFalse($version->isNew());
    }
}
