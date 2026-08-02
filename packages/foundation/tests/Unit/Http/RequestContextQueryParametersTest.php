<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * #2167: the HTTP kernel must hand providers a {@see RequestContext} carrying
 * the real request's query parameters.
 *
 * `packages/listing`'s ServiceProvider bound an anonymous `new RequestContext()`
 * and its comment promised that "CLI and HTTP kernels override the binding" —
 * an override that was never written. `ListingResolver` reads `?page=` from
 * this object, so pagination was unreachable for **every** listing in **every**
 * application, while `Pagination::hasNext` still reported `true` and pagers
 * rendered links that returned the same rows.
 *
 * These tests exercise the kernel's own construction of the context. The
 * end-to-end proof — page 1 and page 2 returning different rows through a real
 * listing route — lives in the consuming application's suite, because it needs
 * registered listings and seeded entities.
 */
#[CoversClass(HttpKernel::class)]
final class RequestContextQueryParametersTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalGet = [];

    protected function setUp(): void
    {
        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
    }

    /**
     * Build the context the kernel would hand providers, without booting.
     *
     * @param array<string, mixed> $query
     */
    private function contextFor(array $query): RequestContext
    {
        $_GET = $query;

        $kernel = new HttpKernel(sys_get_temp_dir());
        $method = new \ReflectionMethod($kernel, 'requestContextForProviders');

        $context = $method->invoke($kernel);
        self::assertInstanceOf(RequestContext::class, $context);

        return $context;
    }

    #[Test]
    public function the_request_query_reaches_the_context(): void
    {
        // The single fact that was false before #2167.
        self::assertSame(['page' => '2'], $this->contextFor(['page' => '2'])->getQueryParams());
    }

    #[Test]
    public function unrelated_parameters_are_preserved_alongside_page(): void
    {
        // Exposed filter parameters travel the same way, so dropping anything
        // that is not `page` would break filtering as soon as it is wired.
        $params = $this->contextFor(['page' => '3', 'q' => 'water', 'sort' => 'newest'])->getQueryParams();

        self::assertSame(['page' => '3', 'q' => 'water', 'sort' => 'newest'], $params);
    }

    #[Test]
    public function an_empty_query_yields_an_empty_context(): void
    {
        self::assertSame([], $this->contextFor([])->getQueryParams());
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>}> */
    public static function shapes(): iterable
    {
        // `?a=1&a=2` — PHP itself collapses repeats to last-wins before we see
        // them, so the context matches what the rest of PHP believes.
        yield 'repeated scalar (already collapsed by PHP)' => [['a' => '2'], ['a' => '2']];

        // `?a[]=1&a[]=2` — getQueryParams() is declared array<string, string>,
        // so an array value is reduced to its last scalar leaf rather than
        // breaking the contract for every consumer.
        yield 'bracketed array' => [['a' => ['1', '2']], ['a' => '2']];
        yield 'nested array' => [['a' => [['x', 'y']]], ['a' => 'y']];
        yield 'empty array' => [['a' => []], []];

        // Numeric and boolean values can arrive from odd clients; stringify
        // rather than discard.
        yield 'numeric' => [['n' => 7], ['n' => '7']];

        yield 'mixed' => [
            ['page' => '2', 'tags' => ['a', 'b'], 'q' => 'x'],
            ['page' => '2', 'tags' => 'b', 'q' => 'x'],
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('shapes')]
    public function query_shapes_are_represented_within_the_declared_contract(array $query, array $expected): void
    {
        $params = $this->contextFor($query)->getQueryParams();

        self::assertSame($expected, $params);
        foreach ($params as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value, 'getQueryParams() is declared array<string, string>');
        }
    }

    #[Test]
    public function separate_requests_cannot_leak_query_state(): void
    {
        // The context is built per call from the live superglobal and is
        // `final readonly`, so one request's `?page=` can never survive into
        // the next — which would be a cross-request data leak in a worker that
        // serves many requests.
        $first = $this->contextFor(['page' => '2', 'q' => 'first']);
        $second = $this->contextFor(['page' => '5']);
        $third = $this->contextFor([]);

        self::assertSame(['page' => '2', 'q' => 'first'], $first->getQueryParams());
        self::assertSame(['page' => '5'], $second->getQueryParams());
        self::assertSame([], $third->getQueryParams());

        // And the earlier instances are unchanged by the later ones.
        self::assertSame(['page' => '2', 'q' => 'first'], $first->getQueryParams());
    }

    #[Test]
    public function a_manually_constructed_context_is_unchanged(): void
    {
        // Unit tests and library consumers construct this directly; #2167 must
        // not alter that behaviour.
        $manual = new RequestContext(['editor'], 42, 'oj', 'en', ['page' => '9']);

        self::assertSame(['page' => '9'], $manual->getQueryParams());
        self::assertSame(['editor'], $manual->roles());
        self::assertSame(42, $manual->accountId());
        self::assertSame('oj', $manual->activeLangcode());
        self::assertSame('en', $manual->interfaceLangcode());

        self::assertSame([], new RequestContext()->getQueryParams(), 'the anonymous default is still empty');
    }
}
