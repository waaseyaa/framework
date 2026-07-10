<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Cast;

use DateMalformedStringException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Cast\Exception\CastException;
use Waaseyaa\Entity\Cast\ValueCaster;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleIntEnum;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleNestedChildVo;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleNestedParentVo;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleStringEnum;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleUnitEnum;
use Waaseyaa\Entity\Tests\Unit\Cast\Fixture\SampleValueObject;

/**
 * @covers \Waaseyaa\Entity\Cast\ValueCaster
 */
final class ValueCasterTest extends TestCase
{
    private const FIELD = 'f';

    #[Test]
    public function cast_in_returns_null_for_null_for_each_builtin(): void
    {
        $c = new ValueCaster();
        foreach (['int', 'float', 'bool', 'string', 'array', 'datetime_immutable'] as $spec) {
            self::assertNull($c->castIn(self::FIELD, null, $spec));
        }
    }

    #[Test]
    public function cast_out_returns_null_for_null_for_each_builtin(): void
    {
        $c = new ValueCaster();
        foreach (['int', 'float', 'bool', 'string', 'array', 'datetime_immutable'] as $spec) {
            self::assertNull($c->castOut(self::FIELD, null, $spec));
        }
    }

    #[Test]
    #[DataProvider('intRoundTripProvider')]
    public function int_round_trip(mixed $stored, int $expectedDomain): void
    {
        $c = new ValueCaster();
        self::assertSame($expectedDomain, $c->castIn(self::FIELD, $stored, 'int'));
        self::assertSame($expectedDomain, $c->castOut(self::FIELD, $expectedDomain, 'int'));
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function intRoundTripProvider(): iterable
    {
        yield 'int' => [7, 7];
        yield 'numeric string' => ['7', 7];
        yield 'float truncates' => [7.9, 7];
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];
    }

    #[Test]
    public function int_cast_in_rejects_empty_string(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '', 'int');
    }

    #[Test]
    public function int_cast_in_rejects_non_numeric_string(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, 'nope', 'int');
    }

    #[Test]
    #[DataProvider('floatRoundTripProvider')]
    public function float_round_trip(mixed $stored, float $expectedDomain): void
    {
        $c = new ValueCaster();
        self::assertSame($expectedDomain, $c->castIn(self::FIELD, $stored, 'float'));
        self::assertSame($expectedDomain, $c->castOut(self::FIELD, $expectedDomain, 'float'));
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function floatRoundTripProvider(): iterable
    {
        yield 'float' => [1.5, 1.5];
        yield 'int widens' => [2, 2.0];
        yield 'numeric string' => ['2.5', 2.5];
        yield 'true' => [true, 1.0];
        yield 'false' => [false, 0.0];
    }

    #[Test]
    public function float_cast_in_rejects_empty_string(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '', 'float');
    }

    #[Test]
    #[DataProvider('boolRoundTripProvider')]
    public function bool_round_trip(mixed $stored, bool $expected): void
    {
        $c = new ValueCaster();
        self::assertSame($expected, $c->castIn(self::FIELD, $stored, 'bool'));
        self::assertSame($expected, $c->castOut(self::FIELD, $expected, 'bool'));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function boolRoundTripProvider(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'empty string is false' => ['', false];
    }

    #[Test]
    public function bool_cast_in_rejects_float(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, 0.5, 'bool');
    }

    #[Test]
    public function bool_cast_out_rejects_float(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castOut(self::FIELD, 0.5, 'bool');
    }

    #[Test]
    public function string_round_trip(): void
    {
        $c = new ValueCaster();
        self::assertSame('hello', $c->castIn(self::FIELD, 'hello', 'string'));
        self::assertSame('7', $c->castIn(self::FIELD, 7, 'string'));
        self::assertSame('hello', $c->castOut(self::FIELD, 'hello', 'string'));
        self::assertSame('7', $c->castOut(self::FIELD, 7, 'string'));
    }

    #[Test]
    public function string_cast_in_rejects_array(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, [], 'string');
    }

    #[Test]
    public function array_round_trip_json_string(): void
    {
        $c = new ValueCaster();
        $json = '{"a":1,"b":[2,3]}';
        $domain = $c->castIn(self::FIELD, $json, 'array');
        self::assertSame(['a' => 1, 'b' => [2, 3]], $domain);
        $out = $c->castOut(self::FIELD, $domain, 'array');
        self::assertSame($json, $out);
    }

    #[Test]
    public function array_cast_in_passes_through_array(): void
    {
        $c = new ValueCaster();
        $arr = ['x' => 1];
        self::assertSame($arr, $c->castIn(self::FIELD, $arr, 'array'));
    }

    #[Test]
    public function array_cast_in_empty_string_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '', 'array');
    }

    #[Test]
    public function array_cast_in_invalid_json_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '{', 'array');
    }

    #[Test]
    public function array_cast_in_json_scalar_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '42', 'array');
    }

    #[Test]
    public function array_spec_type_json_alias(): void
    {
        $c = new ValueCaster();
        self::assertSame(
            ['k' => 'v'],
            $c->castIn(self::FIELD, '{"k":"v"}', ['type' => 'json']),
        );
    }

    #[Test]
    public function datetime_immutable_round_trip_atom(): void
    {
        $c = new ValueCaster();
        $dt = new DateTimeImmutable('2024-01-15T12:30:45+00:00');
        $stored = $c->castOut(self::FIELD, $dt, 'datetime_immutable');
        self::assertIsString($stored);
        $again = $c->castIn(self::FIELD, $stored, 'datetime_immutable');
        self::assertEquals($dt, $again);
    }

    #[Test]
    public function datetime_immutable_array_spec_default_storage_is_iso8601(): void
    {
        $c = new ValueCaster();
        $spec = ['type' => 'datetime_immutable'];
        $dt = new DateTimeImmutable('2024-06-01T10:00:00+00:00');
        $out = $c->castOut(self::FIELD, $dt, $spec);
        self::assertSame('2024-06-01T10:00:00+00:00', $out);
    }

    #[Test]
    public function datetime_immutable_storage_unix_round_trip(): void
    {
        $c = new ValueCaster();
        $spec = ['type' => 'datetime_immutable', 'storage' => 'unix'];
        $dt = new DateTimeImmutable('@1700000000');
        self::assertSame(1700000000, $c->castOut(self::FIELD, $dt, $spec));
        $again = $c->castIn(self::FIELD, 1700000000, $spec);
        self::assertSame(1700000000, $again->getTimestamp());
    }

    #[Test]
    public function datetime_immutable_unix_storage_domain_null_does_not_throw(): void
    {
        // G-028: WordPress corpora (Sheguiandah pass-1) produce records with no modified
        // date at all. Node's `changed` cast is ['type' => 'datetime_immutable', 'storage' => 'unix'].
        // ValueCaster::castOut() already short-circuits a null $domain before this private
        // helper is ever reached, so this is a white-box regression test for the helper
        // itself: it must not rely on the caller's guard to stay null-safe.
        $c = new ValueCaster();
        $method = new \ReflectionMethod(ValueCaster::class, 'castOutDateTimeImmutable');

        $result = $method->invoke($c, self::FIELD, null, ['type' => 'datetime_immutable', 'storage' => 'unix']);

        self::assertNull($result);
    }

    #[Test]
    public function datetime_immutable_invalid_storage_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castOut(
            self::FIELD,
            new DateTimeImmutable('@0'),
            ['type' => 'datetime_immutable', 'storage' => 'bogus'],
        );
    }

    #[Test]
    public function datetime_immutable_carbon_immutable_domain_round_trip(): void
    {
        if (!class_exists(\Carbon\CarbonImmutable::class)) {
            self::markTestSkipped('nesbot/carbon not installed');
        }

        $c = new ValueCaster();
        $spec = ['type' => 'datetime_immutable', 'domain' => 'carbon_immutable', 'storage' => 'unix'];
        $carbon = \Carbon\CarbonImmutable::parse('2024-03-10T15:00:00Z');
        $stored = $c->castOut(self::FIELD, $carbon, $spec);
        self::assertIsInt($stored);
        $in = $c->castIn(self::FIELD, $stored, $spec);
        self::assertInstanceOf(\Carbon\CarbonImmutable::class, $in);
        self::assertSame($stored, $in->getTimestamp());
    }

    #[Test]
    public function datetime_immutable_cast_in_unix_timestamp_int(): void
    {
        $c = new ValueCaster();
        $ts = 1700000000;
        $dt = $c->castIn(self::FIELD, $ts, 'datetime_immutable');
        self::assertSame($ts, $dt->getTimestamp());
    }

    #[Test]
    public function datetime_immutable_cast_in_numeric_string_timestamp(): void
    {
        $c = new ValueCaster();
        $ts = 1700000000;
        $dt = $c->castIn(self::FIELD, (string) $ts, 'datetime_immutable');
        self::assertSame($ts, $dt->getTimestamp());
    }

    #[Test]
    public function datetime_immutable_cast_in_empty_string_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '', 'datetime_immutable');
    }

    #[Test]
    public function datetime_immutable_cast_in_invalid_string_wraps_exception(): void
    {
        $c = new ValueCaster();
        try {
            $c->castIn(self::FIELD, 'not-a-datetime', 'datetime_immutable');
            self::fail('Expected ' . CastException::class);
        } catch (CastException $e) {
            self::assertNotNull($e->getPrevious());
            self::assertInstanceOf(DateMalformedStringException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function backed_string_enum_round_trip(): void
    {
        $c = new ValueCaster();
        $spec = SampleStringEnum::class;
        self::assertSame(
            SampleStringEnum::Alpha,
            $c->castIn(self::FIELD, 'a', $spec),
        );
        self::assertSame('a', $c->castOut(self::FIELD, SampleStringEnum::Alpha, $spec));
    }

    #[Test]
    public function backed_string_enum_try_from_miss_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, 'z', SampleStringEnum::class);
    }

    #[Test]
    public function backed_int_enum_round_trip(): void
    {
        $c = new ValueCaster();
        $spec = SampleIntEnum::class;
        self::assertSame(SampleIntEnum::One, $c->castIn(self::FIELD, 1, $spec));
        self::assertSame(1, $c->castOut(self::FIELD, SampleIntEnum::One, $spec));
    }

    #[Test]
    public function backed_int_enum_numeric_string(): void
    {
        $c = new ValueCaster();
        self::assertSame(
            SampleIntEnum::Two,
            $c->castIn(self::FIELD, '2', SampleIntEnum::class),
        );
    }

    #[Test]
    public function backed_enum_instance_pass_through_on_cast_in(): void
    {
        $c = new ValueCaster();
        self::assertSame(
            SampleStringEnum::Beta,
            $c->castIn(self::FIELD, SampleStringEnum::Beta, SampleStringEnum::class),
        );
    }

    #[Test]
    public function non_backed_enum_spec_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, 'A', SampleUnitEnum::class);
    }

    #[Test]
    public function plain_class_string_requires_value_object_interface(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('Value object cast requires class implementing FromArrayEntityValueInterface');
        (new ValueCaster())->castIn(self::FIELD, 'x', \stdClass::class);
    }

    #[Test]
    public function value_object_round_trip_json_string_storage(): void
    {
        $c = new ValueCaster();
        $spec = SampleValueObject::class;
        $vo = new SampleValueObject(title: 'Hello');
        $json = $c->castOut(self::FIELD, $vo, $spec);
        self::assertSame('{"title":"Hello"}', $json);
        $round = $c->castIn(self::FIELD, $json, $spec);
        self::assertInstanceOf(SampleValueObject::class, $round);
        self::assertSame('Hello', $round->title);
    }

    #[Test]
    public function value_object_cast_in_accepts_php_array_like_hydrate(): void
    {
        $c = new ValueCaster();
        $spec = SampleValueObject::class;
        $vo = $c->castIn(self::FIELD, ['title' => 'Arr'], $spec);
        self::assertInstanceOf(SampleValueObject::class, $vo);
        self::assertSame('Arr', $vo->title);
    }

    #[Test]
    public function value_object_cast_out_accepts_array_domain(): void
    {
        $c = new ValueCaster();
        $spec = SampleValueObject::class;
        $json = $c->castOut(self::FIELD, ['title' => 'From array'], $spec);
        self::assertSame('{"title":"From array"}', $json);
    }

    #[Test]
    public function value_object_cast_out_rejects_scalar_domain(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessage('scalars are not accepted');
        (new ValueCaster())->castOut(self::FIELD, 'nope', SampleValueObject::class);
    }

    #[Test]
    public function value_object_nested_round_trip(): void
    {
        $c = new ValueCaster();
        $spec = SampleNestedParentVo::class;
        $vo = new SampleNestedParentVo(
            child: new SampleNestedChildVo(code: 'inner'),
        );
        $json = $c->castOut(self::FIELD, $vo, $spec);
        self::assertSame('{"child":{"code":"inner"}}', $json);
        $in = $c->castIn(self::FIELD, $json, $spec);
        self::assertInstanceOf(SampleNestedParentVo::class, $in);
        self::assertSame('inner', $in->child->code);
    }

    #[Test]
    public function value_object_array_cast_spec_with_class_key(): void
    {
        $c = new ValueCaster();
        $spec = ['type' => 'value_object', 'class' => SampleValueObject::class];
        $vo = $c->castIn(self::FIELD, '{"title":"Spec"}', $spec);
        self::assertInstanceOf(SampleValueObject::class, $vo);
        self::assertSame('Spec', $vo->title);
    }

    #[Test]
    public function value_object_array_spec_missing_class_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '{}', ['type' => 'value_object']);
    }

    #[Test]
    public function unknown_builtin_token_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '1', 'unknown_cast');
    }

    #[Test]
    public function invalid_array_cast_spec_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '[]', []);
    }

    #[Test]
    public function invalid_array_cast_spec_missing_type_throws(): void
    {
        $this->expectException(CastException::class);
        (new ValueCaster())->castIn(self::FIELD, '[]', ['foo' => 'bar']);
    }
}
