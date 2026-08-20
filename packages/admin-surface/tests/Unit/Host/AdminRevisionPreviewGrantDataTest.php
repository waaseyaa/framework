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

    #[Test]
    public function dots_in_a_query_value_are_not_misclassified_as_path_traversal(): void
    {
        $grant = new AdminRevisionPreviewGrantData(1, '/preview/article/1?next=a..b');

        self::assertSame('/preview/article/1?next=a..b', $grant->previewUrl);
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
        yield 'forward-slash traversal' => [1, '/preview/../etc/passwd'];
        yield 'backslash traversal' => [1, '/preview\\..\\secret'];
        yield 'mixed traversal' => [1, '/preview/..\\secret'];
        yield 'https mixed traversal' => [1, 'https://preview.example.org/a/../b'];
        yield 'rooted backslash path' => [1, '/\\windows\\system32'];
        yield 'encoded traversal' => [1, '/preview/%2e%2e/%2e%2e/secret'];
        yield 'double-encoded traversal' => [1, '/preview/%252e%252e/secret'];
        yield 'encoded backslash traversal' => [1, '/preview%5C%2E%2E%5Csecret'];
        yield 'overlong UTF-8 traversal' => [1, '/preview/%c0%ae%c0%ae/secret'];
        yield 'line-feed protocol-relative escape' => [1, "/\n/evil.example/x"];
        yield 'carriage-return protocol-relative escape' => [1, "/\r/evil.example/x"];
        yield 'tab protocol-relative escape' => [1, "/\t/evil.example/x"];
    }
}
