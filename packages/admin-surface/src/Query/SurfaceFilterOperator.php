<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Query;

enum SurfaceFilterOperator: string
{
    case EQUALS = 'EQUALS';
    case NOT_EQUALS = 'NOT_EQUALS';
    case IN = 'IN';
    case CONTAINS = 'CONTAINS';
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
            self::CONTAINS => 'LIKE',
            self::GT => '>',
            self::LT => '<',
            self::GTE => '>=',
            self::LTE => '<=',
        };
    }
}
