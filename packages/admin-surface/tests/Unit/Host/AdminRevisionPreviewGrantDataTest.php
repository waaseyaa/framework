<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Host\AdminRevisionPreviewGrantData;

#[CoversClass(AdminRevisionPreviewGrantData::class)]
final class AdminRevisionPreviewGrantDataTest extends TestCase
{
    #[Test]
    public function a_grant_carries_the_revision_it_was_issued_for(): void
    {
        $grant = new AdminRevisionPreviewGrantData(7, '/preview/article/1?revision=7');

        self::assertSame(
            ['revisionId' => 7, 'previewUrl' => '/preview/article/1?revision=7'],
            $grant->toArray(),
        );
    }

    #[Test]
    public function an_absolute_https_preview_url_is_accepted(): void
    {
        $grant = new AdminRevisionPreviewGrantData(1, 'https://preview.example.org/article/1');

        self::assertSame('https://preview.example.org/article/1', $grant->previewUrl);
    }

    /**
     * The value object is the boundary an application authority hands back, so
     * it refuses anything that could name no revision or send the operator off
     * the origin.
     */
    #[Test]
    #[DataProvider('refusedGrants')]
    public function a_grant_naming_no_revision_or_leaving_the_origin_is_refused(int $revisionId, string $previewUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminRevisionPreviewGrantData($revisionId, $previewUrl);
    }

    /** @return iterable<string, array{int, string}> */
    public static function refusedGrants(): iterable
    {
        yield 'revision zero' => [0, '/preview/article/1'];
        yield 'negative revision' => [-1, '/preview/article/1'];
        yield 'empty url' => [1, ''];
        yield 'protocol-relative url' => [1, '//preview.example.org/article/1'];
        yield 'plaintext url' => [1, 'http://preview.example.org/article/1'];
        yield 'opaque scheme' => [1, 'javascript:alert(1)'];
    }
}
