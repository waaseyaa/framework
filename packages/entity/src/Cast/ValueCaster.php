<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Cast;

use BackedEnum;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionEnum;
use Waaseyaa\Entity\Cast\Exception\CastException;
use Waaseyaa\TypedData\Coercion\CoercionException;
use Waaseyaa\TypedData\Coercion\EntityCastCoercion;

/**
 * Converts values between storage shapes and PHP domain types for entity field casts.
 *
 * Builtin scalar and JSON-array casts delegate to {@see EntityCastCoercion} in `waaseyaa/typed-data` (#1185).
 * {@code datetime_immutable} and backed enums remain here (#1183 / enum handling).
 * Value objects use {@see FromArrayEntityValueInterface} with the same storage shape as the {@code array} cast (#1184).
 *
 * Optional Carbon: array spec `['type' => 'datetime_immutable', 'domain' => 'carbon_immutable']` requires
 * `nesbot/carbon` (#1183).
 *
 * Backed-enum handling intentionally uses {@see ReflectionEnum} to validate `isBacked()` and read backing
 * types; there is no smaller surface API for the same checks (audit hygiene flags `new Reflection` generically).
 */
final class ValueCaster
{
    private const BUILTIN_INT = 'int';

    private const BUILTIN_FLOAT = 'float';

    private const BUILTIN_BOOL = 'bool';

    private const BUILTIN_STRING = 'string';

    private const BUILTIN_ARRAY = 'array';

    private const BUILTIN_DATETIME_IMMUTABLE = 'datetime_immutable';

    /**
     * @var list<string>
     */
    private const BUILTIN_TOKENS = [
        self::BUILTIN_INT,
        self::BUILTIN_FLOAT,
        self::BUILTIN_BOOL,
        self::BUILTIN_STRING,
        self::BUILTIN_ARRAY,
        self::BUILTIN_DATETIME_IMMUTABLE,
    ];

    /**
     * Storage → domain (e.g. JSON string → array, ISO-8601 → DateTimeImmutable).
     *
     * @param string|array<string, mixed> $castSpec
     */
    public function castIn(string $field, mixed $stored, string|array $castSpec): mixed
    {
        if ($stored === null) {
            return null;
        }

        $resolved = $this->resolveCastTarget($field, $castSpec);

        if ($resolved['kind'] === 'builtin') {
            return $this->castInBuiltin($field, $stored, $resolved['token'], $castSpec);
        }

        if ($resolved['kind'] === 'value_object') {
            /** @var class-string<FromArrayEntityValueInterface> $voClass */
            $voClass = $resolved['class'];

            return $this->castInValueObject($field, $stored, $voClass);
        }

        /** @var class-string<BackedEnum> $enumClass */
        $enumClass = $resolved['class'];

        return $this->castInBackedEnum($field, $stored, $enumClass);
    }

    /**
     * Domain → storage (canonical scalars / JSON strings as documented per kind).
     *
     * @param string|array<string, mixed> $castSpec
     */
    public function castOut(string $field, mixed $domain, string|array $castSpec): mixed
    {
        if ($domain === null) {
            return null;
        }

        $resolved = $this->resolveCastTarget($field, $castSpec);

        if ($resolved['kind'] === 'builtin') {
            return $this->castOutBuiltin($field, $domain, $resolved['token'], $castSpec);
        }

        if ($resolved['kind'] === 'value_object') {
            /** @var class-string<FromArrayEntityValueInterface> $voClass */
            $voClass = $resolved['class'];

            return $this->castOutValueObject($field, $domain, $voClass);
        }

        /** @var class-string<BackedEnum> $enumClass */
        $enumClass = $resolved['class'];

        return $this->castOutBackedEnum($field, $domain, $enumClass);
    }

    /**
     * Whether {@code $class} is a value-object cast target (single extension seam for VO detection).
     *
     * @param class-string $class
     */
    private function isValueObjectCast(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, FromArrayEntityValueInterface::class);
    }

    /**
     * @return array{kind: 'builtin', token: string}|array{kind: 'enum', class: class-string<BackedEnum>}|array{kind: 'value_object', class: class-string<FromArrayEntityValueInterface>}
     */
    private function resolveCastTarget(string $field, string|array $castSpec): array
    {
        if (is_array($castSpec)) {
            $declaredType = $castSpec['type'] ?? '';
            if ($declaredType === 'value_object') {
                $class = $castSpec['class'] ?? null;
                if (!is_string($class) || $class === '' || !class_exists($class)) {
                    throw CastException::invalidCastSpec($field);
                }

                if (!$this->isValueObjectCast($class)) {
                    throw CastException::valueObjectRequiresInterface($field, $class);
                }

                /** @var class-string<FromArrayEntityValueInterface> $class */
                return ['kind' => 'value_object', 'class' => $class];
            }
        }

        $token = $this->normalizeSpecToToken($field, $castSpec);
        if ($token === '') {
            throw CastException::invalidCastSpec($field);
        }

        if (in_array($token, self::BUILTIN_TOKENS, true)) {
            return ['kind' => 'builtin', 'token' => $token];
        }

        if (!enum_exists($token)) {
            if (class_exists($token)) {
                if ($this->isValueObjectCast($token)) {
                    /** @var class-string<FromArrayEntityValueInterface> $token */
                    return ['kind' => 'value_object', 'class' => $token];
                }

                throw CastException::valueObjectRequiresInterface($field, $token);
            }

            throw CastException::unknownBuiltinCast($field, $token);
        }

        $reflection = new ReflectionEnum($token);
        if (!$reflection->isBacked()) {
            throw CastException::invalidCastSpec($field);
        }

        /** @var class-string<BackedEnum> $token */
        return ['kind' => 'enum', 'class' => $token];
    }

    /**
     * @param string|array<string, mixed> $castSpec
     */
    private function normalizeSpecToToken(string $field, string|array $castSpec): string
    {
        if (is_string($castSpec)) {
            return $castSpec;
        }

        if (!isset($castSpec['type']) || !is_string($castSpec['type']) || $castSpec['type'] === '') {
            throw CastException::invalidCastSpec($field);
        }

        $type = $castSpec['type'];

        return $type === 'json' ? self::BUILTIN_ARRAY : $type;
    }

    /**
     * @param string|array<string, mixed> $originalSpec
     */
    private function castInBuiltin(string $field, mixed $stored, string $token, string|array $originalSpec): mixed
    {
        return match ($token) {
            self::BUILTIN_INT => $this->typedCastIn(
                static fn() => EntityCastCoercion::castInInt($field, $stored),
            ),
            self::BUILTIN_FLOAT => $this->typedCastIn(
                static fn() => EntityCastCoercion::castInFloat($field, $stored),
            ),
            self::BUILTIN_BOOL => $this->typedCastIn(
                static fn() => EntityCastCoercion::castInBool($field, $stored),
            ),
            self::BUILTIN_STRING => $this->typedCastIn(
                static fn() => EntityCastCoercion::castInString($field, $stored),
            ),
            self::BUILTIN_ARRAY => $this->typedCastIn(
                static fn() => EntityCastCoercion::castInArray($field, $stored),
            ),
            self::BUILTIN_DATETIME_IMMUTABLE => $this->castInDateTimeImmutable($field, $stored, $originalSpec),
            default => throw CastException::unknownBuiltinCast($field, $token),
        };
    }

    /**
     * @param string|array<string, mixed> $originalSpec
     */
    private function castOutBuiltin(string $field, mixed $domain, string $token, string|array $originalSpec): mixed
    {
        return match ($token) {
            self::BUILTIN_INT => $this->typedCastOut(
                static fn() => EntityCastCoercion::castOutInt($field, $domain),
            ),
            self::BUILTIN_FLOAT => $this->typedCastOut(
                static fn() => EntityCastCoercion::castOutFloat($field, $domain),
            ),
            self::BUILTIN_BOOL => $this->typedCastOut(
                static fn() => EntityCastCoercion::castOutBool($field, $domain),
            ),
            self::BUILTIN_STRING => $this->typedCastOut(
                static fn() => EntityCastCoercion::castOutString($field, $domain),
            ),
            self::BUILTIN_ARRAY => $this->typedCastOut(
                static fn() => EntityCastCoercion::castOutArray($field, $domain),
            ),
            self::BUILTIN_DATETIME_IMMUTABLE => $this->castOutDateTimeImmutable($field, $domain, $originalSpec),
            default => throw CastException::unknownBuiltinCast($field, $token),
        };
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private function typedCastIn(callable $run): mixed
    {
        try {
            return $run();
        } catch (CoercionException $e) {
            throw new CastException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private function typedCastOut(callable $run): mixed
    {
        try {
            return $run();
        } catch (CoercionException $e) {
            throw new CastException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param string|array<string, mixed> $castSpec
     */
    private function castInDateTimeImmutable(string $field, mixed $stored, string|array $castSpec): DateTimeImmutable
    {
        $dt = $this->resolveStoredToDateTimeImmutable($field, $stored);

        return $this->applyDatetimeDomain($field, $dt, $castSpec);
    }

    private function resolveStoredToDateTimeImmutable(string $field, mixed $stored): DateTimeImmutable
    {
        if ($stored instanceof DateTimeImmutable) {
            return $stored;
        }

        if ($stored instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($stored);
        }

        if (is_int($stored)) {
            return $this->dateTimeFromUnixTimestamp($field, $stored);
        }

        if (is_string($stored)) {
            if ($stored === '') {
                throw CastException::invalidStoredValue(
                    $field,
                    self::BUILTIN_DATETIME_IMMUTABLE,
                    $stored,
                    'Empty string is not a valid datetime.',
                );
            }

            if (ctype_digit($stored)) {
                return $this->dateTimeFromUnixTimestamp($field, (int) $stored);
            }

            try {
                return new DateTimeImmutable($stored);
            } catch (DateMalformedStringException $e) {
                throw CastException::invalidStoredValue(
                    $field,
                    self::BUILTIN_DATETIME_IMMUTABLE,
                    $stored,
                    $e->getMessage(),
                    $e,
                );
            }
        }

        throw CastException::invalidStoredValue($field, self::BUILTIN_DATETIME_IMMUTABLE, $stored);
    }

    /**
     * @param string|array<string, mixed> $castSpec
     */
    private function applyDatetimeDomain(string $field, DateTimeImmutable $dt, string|array $castSpec): DateTimeImmutable
    {
        $domain = is_array($castSpec) ? ($castSpec['domain'] ?? null) : null;
        if ($domain === null || $domain === '') {
            return $dt;
        }

        if ($domain !== 'carbon_immutable') {
            throw CastException::invalidCastSpec($field);
        }

        if (!class_exists(\Carbon\CarbonImmutable::class)) {
            throw CastException::carbonImmutableNotInstalled($field);
        }

        return \Carbon\CarbonImmutable::instance($dt);
    }

    private function dateTimeFromUnixTimestamp(string $field, int $timestamp): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable('@' . (string) $timestamp);
        } catch (DateMalformedStringException $e) {
            throw CastException::invalidStoredValue(
                $field,
                self::BUILTIN_DATETIME_IMMUTABLE,
                $timestamp,
                $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @param string|array<string, mixed> $castSpec
     *
     * @return int|string|null
     */
    private function castOutDateTimeImmutable(string $field, mixed $domain, string|array $castSpec): int|string|null
    {
        // G-028: null tolerance also lives here, not only in castOut()'s top-level guard —
        // this keeps the helper itself null-safe regardless of caller. WordPress corpora
        // (Sheguiandah pass-1) produce records with no modified date at all; Node's
        // created/changed casts (['type' => 'datetime_immutable', 'storage' => 'unix'])
        // must tolerate that as null in / null out rather than throwing.
        if ($domain === null) {
            return null;
        }

        $storage = $this->resolveDatetimeStorageFormat($field, $castSpec);
        $immutable = $this->normalizeDomainToDateTimeImmutable($field, $domain);

        if ($storage === 'unix') {
            return $immutable->getTimestamp();
        }

        return $immutable->format(DateTimeInterface::ATOM);
    }

    /**
     * @param string|array<string, mixed> $castSpec
     *
     * @return 'unix'|'iso8601'
     */
    private function resolveDatetimeStorageFormat(string $field, string|array $castSpec): string
    {
        if (is_string($castSpec)) {
            return 'iso8601';
        }

        $storage = $castSpec['storage'] ?? 'iso8601';
        if (!is_string($storage) || ($storage !== 'unix' && $storage !== 'iso8601')) {
            throw CastException::invalidDatetimeStorage($field, is_string($storage) ? $storage : get_debug_type($storage));
        }

        return $storage;
    }

    private function normalizeDomainToDateTimeImmutable(string $field, mixed $domain): DateTimeImmutable
    {
        if ($domain instanceof DateTimeImmutable) {
            return $domain;
        }

        if ($domain instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($domain);
        }

        if (is_string($domain) && $domain !== '') {
            return $this->resolveStoredToDateTimeImmutable($field, $domain);
        }

        if (is_int($domain)) {
            return $this->dateTimeFromUnixTimestamp($field, $domain);
        }

        throw CastException::invalidDomainValue($field, self::BUILTIN_DATETIME_IMMUTABLE, $domain);
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    private function castInBackedEnum(string $field, mixed $stored, string $enumClass): BackedEnum
    {
        if ($stored instanceof BackedEnum && $stored::class === $enumClass) {
            return $stored;
        }

        $backingType = new ReflectionEnum($enumClass)->getBackingType();
        if ($backingType === null) {
            throw CastException::invalidCastSpec($field);
        }

        $backingName = $backingType->getName();

        if ($backingName === 'string' && is_string($stored)) {
            $case = $enumClass::tryFrom($stored);
            if ($case === null) {
                throw CastException::invalidStoredValue(
                    $field,
                    $enumClass,
                    $stored,
                    sprintf('No %s case for stored value.', $enumClass),
                );
            }

            return $case;
        }

        if ($backingName === 'int' && is_int($stored)) {
            $case = $enumClass::tryFrom($stored);
            if ($case === null) {
                throw CastException::invalidStoredValue(
                    $field,
                    $enumClass,
                    $stored,
                    sprintf('No %s case for stored value.', $enumClass),
                );
            }

            return $case;
        }

        if ($backingName === 'int' && is_string($stored) && ctype_digit($stored)) {
            return $this->castInBackedEnum($field, (int) $stored, $enumClass);
        }

        throw CastException::invalidStoredValue(
            $field,
            $enumClass,
            $stored,
            'Stored value type does not match enum backing type.',
        );
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    private function castOutBackedEnum(string $field, mixed $domain, string $enumClass): string|int
    {
        if ($domain instanceof BackedEnum && $domain::class === $enumClass) {
            return $domain->value;
        }

        $backingType = new ReflectionEnum($enumClass)->getBackingType();
        if ($backingType === null) {
            throw CastException::invalidCastSpec($field);
        }

        $backingName = $backingType->getName();

        if ($backingName === 'string' && is_string($domain)) {
            $case = $enumClass::tryFrom($domain);
            if ($case === null) {
                throw CastException::invalidDomainValue(
                    $field,
                    $enumClass,
                    $domain,
                    sprintf('No %s case for value.', $enumClass),
                );
            }

            return $case->value;
        }

        if ($backingName === 'int' && is_int($domain)) {
            $case = $enumClass::tryFrom($domain);
            if ($case === null) {
                throw CastException::invalidDomainValue(
                    $field,
                    $enumClass,
                    $domain,
                    sprintf('No %s case for value.', $enumClass),
                );
            }

            return $case->value;
        }

        throw CastException::invalidDomainValue($field, $enumClass, $domain);
    }

    /**
     * @param class-string<FromArrayEntityValueInterface> $voClass
     */
    private function castInValueObject(string $field, mixed $stored, string $voClass): FromArrayEntityValueInterface
    {
        if ($stored instanceof FromArrayEntityValueInterface && $stored::class === $voClass) {
            return $stored;
        }

        $data = $this->typedCastIn(
            static fn() => EntityCastCoercion::castInArray($field, $stored),
        );

        try {
            return $voClass::fromArray($data);
        } catch (\Throwable $e) {
            throw new CastException(
                sprintf('Cannot cast stored value for field "%s" to value object %s.', $field, $voClass),
                0,
                $e,
            );
        }
    }

    /**
     * @param class-string<FromArrayEntityValueInterface> $voClass
     *
     * @return non-falsy-string JSON string (same storage shape as {@code array} cast).
     */
    private function castOutValueObject(string $field, mixed $domain, string $voClass): string
    {
        $vo = $this->normalizeDomainToValueObject($field, $domain, $voClass);

        return $this->typedCastOut(
            static fn() => EntityCastCoercion::castOutArray($field, $vo->toArray()),
        );
    }

    /**
     * @param class-string<FromArrayEntityValueInterface> $voClass
     */
    private function normalizeDomainToValueObject(string $field, mixed $domain, string $voClass): FromArrayEntityValueInterface
    {
        if ($domain instanceof FromArrayEntityValueInterface && $domain::class === $voClass) {
            return $domain;
        }

        if (is_array($domain)) {
            try {
                return $voClass::fromArray($domain);
            } catch (\Throwable $e) {
                throw new CastException(
                    sprintf('Cannot cast domain value for field "%s" to storage for value object %s.', $field, $voClass),
                    0,
                    $e,
                );
            }
        }

        throw CastException::invalidDomainValue(
            $field,
            $voClass,
            $domain,
            'Value object cast accepts only an instance of the cast class or an array (fromArray); scalars are not accepted.',
        );
    }
}
