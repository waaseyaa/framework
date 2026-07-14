<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/** @internal */
final class ParameterTypeInferrer
{
    public static function scalar(mixed $value): ParameterType
    {
        return match (true) {
            is_int($value), is_bool($value) => ParameterType::INTEGER,
            $value === null => ParameterType::NULL,
            // DBAL has no FLOAT ParameterType; an explicit string binding lets
            // each platform perform its numeric conversion without truncation.
            is_float($value) => ParameterType::STRING,
            default => ParameterType::STRING,
        };
    }

    /** @param array<mixed> $values */
    public static function array(array $values): ArrayParameterType
    {
        if ($values === []) {
            return ArrayParameterType::STRING;
        }
        $first = reset($values);

        return is_int($first) || is_bool($first)
            ? ArrayParameterType::INTEGER
            : ArrayParameterType::STRING;
    }
}
