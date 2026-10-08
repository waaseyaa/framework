<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Listing\Exception\ListingCoercionException;
use Waaseyaa\Listing\ExposedFilterCoercer;
use Waaseyaa\Listing\ExposedFilterParser;
use Waaseyaa\Listing\ExposedFilterValues;
use Waaseyaa\Listing\FilterDefinition;
use Waaseyaa\Listing\ListingDefinition;
use Waaseyaa\Listing\Operator;

/**
 * Unit tests for {@see ExposedFilterParser}.
 *
 * Covers FR-044 (permissive default) and FR-045 (strict mode), plus the
 * stable factory shape (`create()`, `strict()`, `withLogger()`,
 * `withTypeResolver()`).
 */
#[CoversClass(ExposedFilterParser::class)]
#[CoversClass(ExposedFilterValues::class)]
#[CoversClass(ExposedFilterCoercer::class)]
#[CoversClass(ListingCoercionException::class)]
final class ExposedFilterParserTest extends TestCase
{
    // ---- Happy path --------------------------------------------------------

    #[Test]
    public function parsesExposedFiltersIntoValueMap(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('title', Operator::EQ, '', 'q'),
                new FilterDefinition('status', Operator::EQ, 1, 'state'),
            ],
        );
        $parser = ExposedFilterParser::create();
        $result = $parser->parse(['q' => 'hello', 'state' => '0'], $def);

        self::assertSame('hello', $result->get('q'));
        self::assertSame(0, $result->get('state'));
    }

    #[Test]
    public function emptyQueryParamsProducesEmptyValues(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('title', Operator::EQ, '', 'q'),
            ],
        );
        $result = ExposedFilterParser::create()->parse([], $def);
        self::assertSame([], $result->all());
    }

    #[Test]
    public function emptyStringRawIsTreatedAsAbsent(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('title', Operator::EQ, '', 'q'),
            ],
        );
        $result = ExposedFilterParser::create()->parse(['q' => ''], $def);
        self::assertFalse($result->has('q'));
    }

    #[Test]
    public function filterWithoutExposedParamIsIgnored(): void
    {
        // FilterDefinition with $exposedParam === null is skipped entirely.
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('status', Operator::EQ, 1),
            ],
        );
        $result = ExposedFilterParser::create()->parse(['status' => '0'], $def);
        self::assertSame([], $result->all());
    }

    #[Test]
    public function unknownRawParamsAreIgnored(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('title', Operator::EQ, '', 'q'),
            ],
        );
        $result = ExposedFilterParser::create()->parse(
            ['q' => 'hello', 'rogue' => 'ignored'],
            $def,
        );
        self::assertSame(['q' => 'hello'], $result->all());
    }

    // ---- Permissive vs strict mode -----------------------------------------

    #[Test]
    public function permissiveModeSilentlyDropsCoercionFailures(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('status', Operator::EQ, 1, 'state'),
                new FilterDefinition('title', Operator::EQ, '', 'q'),
            ],
        );
        $logger = new FakeLogger();
        $parser = ExposedFilterParser::create()->withLogger($logger);
        $result = $parser->parse(['state' => 'not-a-number', 'q' => 'hello'], $def);

        self::assertFalse($result->has('state'), 'malformed coercion must be silently dropped');
        self::assertSame('hello', $result->get('q'));
        self::assertCount(1, $logger->debugCalls, 'one debug log per drop');
        self::assertSame('state', $logger->debugCalls[0]['context']['param']);
        self::assertSame('eq', $logger->debugCalls[0]['context']['operator']);
    }

    #[Test]
    public function strictModeRethrowsListingCoercionException(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('status', Operator::EQ, 1, 'state'),
            ],
        );
        $parser = ExposedFilterParser::create()->strict();

        try {
            $parser->parse(['state' => 'not-a-number'], $def);
            self::fail('expected ListingCoercionException');
        } catch (ListingCoercionException $e) {
            self::assertSame('state', $e->param);
            self::assertSame('not-a-number', $e->raw);
            self::assertSame('eq', $e->operatorName);
            self::assertSame('int', $e->expectedType);
        }
    }

    #[Test]
    public function strictFactoryReturnsNewInstanceAndDoesNotMutateOriginal(): void
    {
        $original = ExposedFilterParser::create();
        $strict = $original->strict();
        self::assertNotSame($original, $strict);

        // Re-prove the original is still permissive.
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('status', Operator::EQ, 1, 'state')],
        );
        $result = $original->parse(['state' => 'bad'], $def);
        self::assertSame([], $result->all());
    }

    #[Test]
    public function permissiveLoggerNotCalledOnSuccess(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('title', Operator::EQ, '', 'q')],
        );
        $logger = new FakeLogger();
        ExposedFilterParser::create()->withLogger($logger)->parse(['q' => 'hello'], $def);
        self::assertSame([], $logger->debugCalls);
    }

    #[Test]
    public function permissiveModeDropsNonStringRawValueAndLogs(): void
    {
        // $_GET arrays (?q[]=a&q[]=b) arrive as array; the parser treats
        // them as unprocessable for scalar operators in permissive mode.
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('title', Operator::EQ, '', 'q')],
        );
        $logger = new FakeLogger();
        $result = ExposedFilterParser::create()
            ->withLogger($logger)
            ->parse(['q' => 12345], $def);
        self::assertFalse($result->has('q'));
        self::assertCount(1, $logger->debugCalls);
    }

    #[Test]
    public function strictModeThrowsOnNonStringRawValue(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('title', Operator::EQ, '', 'q')],
        );
        $this->expectException(ListingCoercionException::class);
        ExposedFilterParser::create()->strict()->parse(['q' => 12345], $def);
    }

    // ---- Type resolver injection -------------------------------------------

    #[Test]
    public function customTypeResolverIsInvokedPerFilter(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [
                new FilterDefinition('createdAt', Operator::GTE, '', 'since'),
            ],
        );
        $parser = ExposedFilterParser::create()
            ->withTypeResolver(static fn(FilterDefinition $f): string => 'datetime');
        $result = $parser->parse(['since' => '2026-05-16'], $def);
        self::assertSame('2026-05-16', $result->get('since'));
    }

    #[Test]
    public function defaultTypeResolverInfersIntFromDefaultValue(): void
    {
        // FilterDefinition::$value is int 1 → default resolver should
        // pick "int" → coercer should produce an int.
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('status', Operator::EQ, 1, 'state')],
        );
        $result = ExposedFilterParser::create()->parse(['state' => '5'], $def);
        self::assertSame(5, $result->get('state'));
    }

    #[Test]
    public function inferenceFallsBackToStringForNullOrEmptyValue(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('title', Operator::EQ, null, 'q')],
        );
        $result = ExposedFilterParser::create()->parse(['q' => 'hello'], $def);
        self::assertSame('hello', $result->get('q'));
    }

    #[Test]
    public function inferenceFromListPicksFirstElementType(): void
    {
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('uid', Operator::IN, [1, 2, 3], 'uids')],
        );
        $result = ExposedFilterParser::create()->parse(['uids' => '10,20'], $def);
        self::assertSame([10, 20], $result->get('uids'));
    }

    #[Test]
    public function createReturnsParserInPermissiveModeWithNullLogger(): void
    {
        // Smoke: construction must not raise even with no overrides.
        $parser = ExposedFilterParser::create();
        $def = new ListingDefinition(
            id: 'recent',
            entityType: 'node',
            filters: [new FilterDefinition('title', Operator::EQ, '', 'q')],
        );
        $result = $parser->parse(['q' => 'hello'], $def);
        self::assertSame('hello', $result->get('q'));
    }
}

/**
 * Test-only logger that records `debug()` invocations.
 *
 * Implements the minimum interface surface required by
 * {@see ExposedFilterParser} (only `debug()` is actually called); other
 * methods are no-ops that record into a generic bucket so unexpected
 * usage shows up in test failures.
 */
final class FakeLogger implements LoggerInterface
{
    /**
     * @var list<array{message: string, context: array<string, mixed>}>
     */
    public array $debugCalls = [];

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }

    public function log(LogLevel $level, string|Stringable $message, array $context = []): void
    {
        $this->debugCalls[] = ['message' => (string) $message, 'context' => $context];
    }
}
