<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Query;

/**
 * @api
 */
enum SurfaceFilterOperator: string
{
    case EQUALS = 'EQUALS';
    case NOT_EQUALS = 'NOT_EQUALS';
    case IN = 'IN';
    case CONTAINS = 'CONTAINS';
    case STARTS_WITH = 'STARTS_WITH';
    case GT = 'GT';
    case LT = 'LT';
    case GTE = 'GTE';
    case LTE = 'LTE';

    public static function fromString(string $name): ?self
    {
        return self::tryFrom(strtoupper($name));
    }

    public function toSqlOperator(): string
    {
        return match ($this) {
            self::EQUALS => '=',
            self::NOT_EQUALS => '!=',
            self::IN => 'IN',
            self::CONTAINS, self::STARTS_WITH => 'LIKE',
            self::GT => '>',
            self::LT => '<',
            self::GTE => '>=',
            self::LTE => '<=',
        };
    }
}
