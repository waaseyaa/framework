<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Listing\Exception\ListingCoercionException;
use Waaseyaa\Listing\ExposedFilterCoercer;
use Waaseyaa\Listing\Operator;

/**
 * Unit tests for {@see ExposedFilterCoercer}.
 *
 * Covers the coercion matrix in
 * `kitty-specs/listing-pipeline-v1-01KRMN0B/contracts/exposed-filters.md`
 * (FR-043). Each scalar type, each operator family, plus negative paths.
 */
#[CoversClass(ExposedFilterCoercer::class)]
#[CoversClass(ListingCoercionException::class)]
final class ExposedFilterCoercerTest extends TestCase
{
    private ExposedFilterCoercer $coercer;

    protected function setUp(): void
    {
        $this->coercer = new ExposedFilterCoercer();
    }

    // ---- Scalar operators ---------------------------------------------------

    #[Test]
    public function eqStringPassesRawThrough(): void
    {
        $result = $this->coercer->coerce('q', 'hello', Operator::EQ, 'string');
        self::assertSame('hello', $result);
    }

    #[Test]
    public function eqIntCoercesNumericString(): void
    {
        $result = $this->coercer->coerce('status', '42', Operator::EQ, 'int');
        self::assertSame(42, $result);
    }

    #[Test]
    public function eqIntCoercesNegative(): void
    {
        $result = $this->coercer->coerce('balance', '-7', Operator::EQ, 'int');
        self::assertSame(-7, $result);
    }

    #[Test]
    public function eqIntegerAliasIsAccepted(): void
    {
        $result = $this->coercer->coerce('status', '5', Operator::EQ, 'integer');
        self::assertSame(5, $result);
    }

    #[Test]
    public function eqIntThrowsOnNonNumeric(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('integer literal');
        $this->coercer->coerce('status', 'not-a-number', Operator::EQ, 'int');
    }

    #[Test]
    public function eqIntThrowsOnFloatLiteral(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->coercer->coerce('status', '3.14', Operator::EQ, 'int');
    }

    #[Test]
    public function ltFloatCoercesDecimal(): void
    {
        $result = $this->coercer->coerce('rating', '4.5', Operator::LT, 'float');
        self::assertSame(4.5, $result);
    }

    #[Test]
    public function gtDoubleAliasIsAccepted(): void
    {
        $result = $this->coercer->coerce('rating', '2.0', Operator::GT, 'double');
        self::assertSame(2.0, $result);
    }

    #[Test]
    public function floatThrowsOnNonNumeric(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('float literal');
        $this->coercer->coerce('rating', 'abc', Operator::LT, 'float');
    }

    #[Test]
    public function eqBoolTrueLiteralsAreAccepted(): void
    {
        foreach (['1', 'true', 'yes', 'on'] as $raw) {
            $result = $this->coercer->coerce('flag', $raw, Operator::EQ, 'bool');
            self::assertTrue($result, sprintf('"%s" should coerce to true', $raw));
        }
    }

    #[Test]
    public function eqBoolFalseLiteralsAreAccepted(): void
    {
        foreach (['0', 'false', 'no', 'off'] as $raw) {
            $result = $this->coercer->coerce('flag', $raw, Operator::EQ, 'bool');
            self::assertFalse($result, sprintf('"%s" should coerce to false', $raw));
        }
    }

    #[Test]
    public function booleanAliasIsAccepted(): void
    {
        $result = $this->coercer->coerce('flag', 'true', Operator::EQ, 'boolean');
        self::assertTrue($result);
    }

    #[Test]
    public function boolThrowsOnGibberish(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('boolean literal');
        $this->coercer->coerce('flag', 'maybe', Operator::EQ, 'bool');
    }

    #[Test]
    public function eqDateTimeCoercesIsoString(): void
    {
        $result = $this->coercer->coerce('createdAt', '2026-05-16T12:00:00Z', Operator::EQ, 'datetime');
        self::assertSame('2026-05-16T12:00:00Z', $result);
    }

    #[Test]
    public function dateAliasIsAccepted(): void
    {
        $result = $this->coercer->coerce('createdAt', '2026-05-16', Operator::GTE, 'date');
        self::assertSame('2026-05-16', $result);
    }

    #[Test]
    public function dateTimeThrowsOnUnparseable(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('date/time');
        $this->coercer->coerce('createdAt', 'not-a-date', Operator::EQ, 'datetime');
    }

    #[Test]
    public function dateListsKeepScalarStorageRepresentation(): void
    {
        self::assertSame(['2026-01-01', '2026-12-31'], $this->coercer->coerce('date', '2026-01-01,2026-12-31', Operator::IN, 'date'));
    }

    #[Test]
    public function invalidCalendarDatesAreRefused(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->coercer->coerce('date', '2026-02-30', Operator::EQ, 'date');
    }

    #[Test]
    public function unsupportedTypeThrows(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('unsupported typed-data type');
        $this->coercer->coerce('weird', 'value', Operator::EQ, 'magicType');
    }

    // ---- IN / NOT_IN --------------------------------------------------------

    #[Test]
    public function inSplitsOnCommaAndCoercesEachElement(): void
    {
        $result = $this->coercer->coerce('tags', 'a,b,c', Operator::IN, 'string');
        self::assertSame(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function notInSplitsOnCommaAndCoercesInts(): void
    {
        $result = $this->coercer->coerce('uids', '1,2,3', Operator::NOT_IN, 'int');
        self::assertSame([1, 2, 3], $result);
    }

    #[Test]
    public function inThrowsOnEmptyRaw(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('empty list');
        $this->coercer->coerce('tags', '', Operator::IN, 'string');
    }

    #[Test]
    public function inThrowsOnEmptyElement(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('empty element');
        $this->coercer->coerce('tags', 'a,,c', Operator::IN, 'string');
    }

    #[Test]
    public function inThrowsWhenAnyElementFailsCoercion(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->coercer->coerce('uids', '1,notanumber,3', Operator::IN, 'int');
    }

    // ---- BETWEEN ------------------------------------------------------------

    #[Test]
    public function betweenSplitsOnTildeAndReturnsTuple(): void
    {
        $result = $this->coercer->coerce('date', '2026-01-01~2026-12-31', Operator::BETWEEN, 'date');
        self::assertIsArray($result);
        self::assertCount(2, $result);
        self::assertSame('2026-01-01', $result[0]);
        self::assertSame('2026-12-31', $result[1]);
    }

    #[Test]
    public function betweenIntegerBoundsCoerce(): void
    {
        $result = $this->coercer->coerce('rank', '1~10', Operator::BETWEEN, 'int');
        self::assertSame([1, 10], $result);
    }

    #[Test]
    public function betweenThrowsOnSinglePart(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('BETWEEN requires');
        $this->coercer->coerce('rank', '5', Operator::BETWEEN, 'int');
    }

    #[Test]
    public function betweenThrowsOnThreeParts(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('BETWEEN requires');
        $this->coercer->coerce('rank', '1~5~10', Operator::BETWEEN, 'int');
    }

    #[Test]
    public function betweenThrowsOnEmptyBound(): void
    {
        $this->expectException(ListingCoercionException::class);
        $this->expectExceptionMessage('BETWEEN low/high bound must not be empty');
        $this->coercer->coerce('rank', '~10', Operator::BETWEEN, 'int');
    }

    // ---- IS_NULL / IS_NOT_NULL ---------------------------------------------

    #[Test]
    public function isNullReturnsNull(): void
    {
        self::assertNull($this->coercer->coerce('flag', '1', Operator::IS_NULL, 'string'));
    }

    #[Test]
    public function isNotNullReturnsNull(): void
    {
        self::assertNull($this->coercer->coerce('flag', 'anything', Operator::IS_NOT_NULL, 'string'));
    }

    // ---- STARTS_WITH / CONTAINS --------------------------------------------

    #[Test]
    public function startsWithReturnsRawStringVerbatim(): void
    {
        // LIKE-pattern escaping is the SQL emitter's responsibility — the
        // parser must pass user-supplied % and _ through unchanged.
        $result = $this->coercer->coerce('title', '50% off_summer', Operator::STARTS_WITH, 'string');
        self::assertSame('50% off_summer', $result);
    }

    #[Test]
    public function containsReturnsRawStringVerbatim(): void
    {
        $result = $this->coercer->coerce('body', 'café', Operator::CONTAINS, 'string');
        self::assertSame('café', $result);
    }

    // ---- Exception payload -------------------------------------------------

    #[Test]
    public function coercionExceptionCarriesFullContext(): void
    {
        try {
            $this->coercer->coerce('status', 'oops', Operator::EQ, 'int');
            self::fail('expected ListingCoercionException');
        } catch (ListingCoercionException $e) {
            self::assertSame('status', $e->param);
            self::assertSame('oops', $e->raw);
            self::assertSame('eq', $e->operatorName);
            self::assertSame('int', $e->expectedType);
            self::assertNotSame('', $e->reason);
        }
    }
}
