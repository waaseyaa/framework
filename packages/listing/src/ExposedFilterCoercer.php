<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use DateTimeImmutable;
use Throwable;
use Waaseyaa\Listing\Exception\ListingCoercionException;

/**
 * Coerces a raw URL-bound string into the typed-data shape required by a
 * {@see FilterDefinition}, applying the operator-aware matrix declared in
 * `docs/specs/listing-pipeline-v1.md`.
 *
 * INTERNAL â€” not part of the stable charter Â§5.X surface. The public
 * entry point is {@see ExposedFilterParser}.
 *
 * The coercer is operator-aware:
 *
 *   - Scalar operators (`EQ`, `NEQ`, `LT`, `LTE`, `GT`, `GTE`) â€” coerce
 *     the raw string per typed-data type (string / int / float / bool /
 *     validated date string).
 *   - `IN` / `NOT_IN` â€” split on `,`, coerce each element per type, return
 *     `list<scalar>`. An empty element or whole-empty list is an error.
 *   - `BETWEEN` â€” split on `~`, expect exactly 2 parts, coerce each per
 *     type, return `[low, high]`.
 *   - `IS_NULL` / `IS_NOT_NULL` â€” the presence of the param is the
 *     signal; return `null`.
 *   - `STARTS_WITH` / `CONTAINS` â€” return the raw string verbatim.
 *     The resolver performs literal Unicode case-folded comparisons; no
 *     SQL LIKE wildcard syntax is introduced.
 */
final class ExposedFilterCoercer
{
    /**
     * Supported typed-data type identifiers for scalar coercion.
     *
     * @var list<non-empty-string>
     */
    private const SUPPORTED_SCALAR_TYPES = [
        'string',
        'int',
        'integer',
        'float',
        'double',
        'bool',
        'boolean',
        'datetime',
        'date',
    ];

    /**
     * @return scalar|list<scalar>|null
     *
     * @throws ListingCoercionException
     */
    public function coerce(
        string $param,
        string $raw,
        Operator $op,
        string $typedDataType,
    ): mixed {
        return match ($op) {
            Operator::EQ,
            Operator::NEQ,
            Operator::LT,
            Operator::LTE,
            Operator::GT,
            Operator::GTE => $this->coerceScalar($param, $raw, $op, $typedDataType),

            Operator::IN,
            Operator::NOT_IN => $this->coerceList($param, $raw, $op, $typedDataType),

            Operator::BETWEEN => $this->coerceTuple($param, $raw, $op, $typedDataType),

            Operator::IS_NULL,
            Operator::IS_NOT_NULL => null,

            Operator::STARTS_WITH,
            Operator::CONTAINS => $raw,
        };
    }

    /**
     * @return scalar
     *
     * @throws ListingCoercionException
     */
    private function coerceScalar(
        string $param,
        string $raw,
        Operator $op,
        string $typedDataType,
    ): mixed {
        $normalized = strtolower($typedDataType);

        return match ($normalized) {
            'string' => $raw,
            'int', 'integer' => $this->toInt($param, $raw, $op, $typedDataType),
            'float', 'double' => $this->toFloat($param, $raw, $op, $typedDataType),
            'bool', 'boolean' => $this->toBool($param, $raw, $op, $typedDataType),
            'datetime', 'date' => $this->toDateString($param, $raw, $op, $typedDataType),
            default => throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: sprintf(
                    'unsupported typed-data type "%s"; expected one of: %s',
                    $typedDataType,
                    implode(', ', self::SUPPORTED_SCALAR_TYPES),
                ),
            ),
        };
    }

    /**
     * @return list<scalar>
     *
     * @throws ListingCoercionException
     */
    private function coerceList(
        string $param,
        string $raw,
        Operator $op,
        string $typedDataType,
    ): array {
        if ($raw === '') {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'empty list value',
            );
        }
        $parts = explode(',', $raw);
        $out = [];
        foreach ($parts as $part) {
            if ($part === '') {
                throw new ListingCoercionException(
                    param: $param,
                    raw: $raw,
                    operatorName: $op->value,
                    expectedType: $typedDataType,
                    reason: 'empty element in comma-separated list',
                );
            }
            /** @var scalar $coerced */
            $coerced = $this->coerceScalar($param, $part, $op, $typedDataType);
            $out[] = $coerced;
        }

        return $out;
    }

    /**
     * @return array{0: scalar, 1: scalar}
     *
     * @throws ListingCoercionException
     */
    private function coerceTuple(
        string $param,
        string $raw,
        Operator $op,
        string $typedDataType,
    ): array {
        $parts = explode('~', $raw);
        if (count($parts) !== 2) {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: sprintf(
                    'BETWEEN requires "<low>~<high>" (exactly two parts separated by "~"); got %d part(s)',
                    count($parts),
                ),
            );
        }
        if ($parts[0] === '' || $parts[1] === '') {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'BETWEEN low/high bound must not be empty',
            );
        }

        /** @var scalar $low */
        $low = $this->coerceScalar($param, $parts[0], $op, $typedDataType);
        /** @var scalar $high */
        $high = $this->coerceScalar($param, $parts[1], $op, $typedDataType);

        return [$low, $high];
    }

    /**
     * @throws ListingCoercionException
     */
    private function toInt(string $param, string $raw, Operator $op, string $typedDataType): int
    {
        $result = filter_var($raw, FILTER_VALIDATE_INT);
        if ($result === false) {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'not a valid integer literal',
            );
        }

        return $result;
    }

    /**
     * @throws ListingCoercionException
     */
    private function toFloat(string $param, string $raw, Operator $op, string $typedDataType): float
    {
        $result = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($result === false) {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'not a valid float literal',
            );
        }

        return $result;
    }

    /**
     * @throws ListingCoercionException
     */
    private function toBool(string $param, string $raw, Operator $op, string $typedDataType): bool
    {
        $result = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($result === null) {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'not a recognised boolean literal (accepted: 1/0, true/false, yes/no, on/off)',
            );
        }

        return $result;
    }

    /**
     * @throws ListingCoercionException
     */
    private function toDateString(
        string $param,
        string $raw,
        Operator $op,
        string $typedDataType,
    ): string {
        try {
            new DateTimeImmutable($raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \InvalidArgumentException('Invalid calendar date.');
            }

            return $raw;
        } catch (Throwable $previous) {
            throw new ListingCoercionException(
                param: $param,
                raw: $raw,
                operatorName: $op->value,
                expectedType: $typedDataType,
                reason: 'unparseable date/time string',
                previous: $previous,
            );
        }
    }
}
