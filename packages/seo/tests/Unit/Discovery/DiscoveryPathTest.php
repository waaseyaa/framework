<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Seo\Discovery\DiscoveryPath;

/**
 * The trust boundary for every application-supplied crawler path.
 *
 * Each rejection case below is a concrete way an application return value could
 * otherwise have become an off-site URL, forged authority, escaped the origin, or
 * corrupted the containing document.
 */
#[CoversClass(DiscoveryPath::class)]
final class DiscoveryPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function rejectedPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'relative without leading slash' => ['news/spring'];
        yield 'absolute https URL' => ['https://evil.example/news'];
        yield 'absolute http URL' => ['http://evil.example/news'];
        yield 'protocol-relative' => ['//evil.example/news'];
        yield 'protocol-relative with credentials' => ['//user:pass@evil.example/news'];
        yield 'backslash authority' => ['/\\evil.example/news'];
        yield 'embedded backslash' => ['/news\\spring'];
        yield 'fragment' => ['/news#section'];
        yield 'parent traversal' => ['/news/../../etc/passwd'];
        yield 'leading traversal' => ['/../secrets'];
        yield 'current-directory segment' => ['/news/./spring'];
        yield 'trailing traversal' => ['/news/..'];
        yield 'null byte' => ["/news\0"];
        yield 'newline' => ["/news\nSitemap: https://evil.example"];
        yield 'carriage return' => ["/news\rspring"];
        yield 'tab' => ["/news\tspring"];
        yield 'space' => ['/news spring'];
        yield 'truncated percent encoding' => ['/news/%'];
        yield 'short percent encoding' => ['/news/%4'];
        yield 'non-hex percent encoding' => ['/news/%zz'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,x'];
    }

    #[Test]
    #[DataProvider('rejectedPaths')]
    public function rejects_any_path_that_is_not_root_relative(string $path): void
    {
        self::assertFalse(DiscoveryPath::acceptsPath($path), $path);
        self::assertFalse(DiscoveryPath::acceptsPathWithQuery($path), $path);
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedPaths(): iterable
    {
        yield 'root' => ['/'];
        yield 'single segment' => ['/health-centre'];
        yield 'nested segments' => ['/news/spring-gathering'];
        yield 'percent encoded segment' => ['/news/spring%20gathering'];
        yield 'numeric id' => ['/node/97'];
        yield 'dot in filename' => ['/files/report.v2.pdf'];
        yield 'colon in segment' => ['/tags/a:b'];
        yield 'at sign in segment' => ['/authors/name@example'];
    }

    #[Test]
    #[DataProvider('acceptedPaths')]
    public function accepts_a_root_relative_path(string $path): void
    {
        self::assertTrue(DiscoveryPath::acceptsPath($path), $path);
        self::assertTrue(DiscoveryPath::acceptsPathWithQuery($path), $path);
    }

    #[Test]
    public function canonical_paths_reject_a_query_string_but_markdown_paths_accept_one(): void
    {
        self::assertFalse(DiscoveryPath::acceptsPath('/node/97?format=md'));
        self::assertTrue(DiscoveryPath::acceptsPathWithQuery('/node/97?format=md'));
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedQueries(): iterable
    {
        yield 'empty query' => ['/node/97?'];
        yield 'fragment after query' => ['/node/97?format=md#x'];
        yield 'newline in query' => ["/node/97?format=md\nx"];
        yield 'space in query' => ['/node/97?format=md x'];
        yield 'backslash in query' => ['/node/97?format=md\\x'];
        yield 'truncated encoding in query' => ['/node/97?format=%'];
        yield 'angle bracket in query' => ['/node/97?format=<md>'];
        yield 'quote in query' => ['/node/97?format="md"'];
    }

    #[Test]
    #[DataProvider('rejectedQueries')]
    public function rejects_a_malformed_query_string(string $path): void
    {
        self::assertFalse(DiscoveryPath::acceptsPathWithQuery($path), $path);
    }

    #[Test]
    public function accepts_a_multi_parameter_query(): void
    {
        self::assertTrue(DiscoveryPath::acceptsPathWithQuery('/node/97?format=md&view=full'));
    }
}
