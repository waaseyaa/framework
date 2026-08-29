<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Why a skill-resource read failed.
 *
 * Kept as an enum rather than folded into the message so callers (tests,
 * a future `bimaaji:doctor`) can branch on the class of failure without
 * string-matching a diagnostic.
 *
 * @api
 */
enum SkillResourceFailure
{
    /** The resource directory is absent, unreadable, or holds no skills. */
    case Missing;

    /** A SKILL.md document exists but cannot be parsed. */
    case Corrupt;
}
