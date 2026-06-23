<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Item\Fixtures;

use Waaseyaa\Field\Item\LabeledCase;

/** String-backed BackedEnum used by EnumItem happy-path tests. */
enum StringEnum: string
{
    case A = 'a';
    case B = 'b';
}

/** Int-backed BackedEnum used by EnumItem int-backed happy-path tests. */
enum IntEnum: int
{
    case Low = 1;
    case High = 9;
}

/**
 * String-backed BackedEnum implementing LabeledCase to exercise the
 * custom-label branch of EnumItem::casesForEnumClass().
 */
enum LabeledStringEnum: string implements LabeledCase
{
    case Draft = 'draft';
    case Published = 'published';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft (work in progress)',
            self::Published => 'Published',
        };
    }
}

/**
 * Empty backed enum (no cases) for EC-5: jsonSchemaFor must still emit
 * a well-formed `{type: 'string', enum: []}`.
 *
 * PHP allows declaring an enum with no cases as long as it's backed and
 * the implementation never tries to instantiate one.
 */
enum EmptyEnum: string {}

/** Unit (non-backed) enum used to exercise the NotABackedEnum error variant. */
enum UnitEnum
{
    case Only;
}

/** Plain class — not an enum at all — for the NotABackedEnum error variant. */
final class NotAnEnum {}
