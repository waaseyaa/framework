<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * How a client wants the canonical skill set delivered to it.
 *
 * Two shapes exist today (see {@see ClientCapabilityRegistry}):
 *
 * - `SingleConsolidatedFile` — every skill folds into one project file
 *   (`AGENTS.md`, `.cursorrules`, `.github/copilot-instructions.md`, …).
 *   The client always loads the whole set.
 * - `PerSkillFile` — one file per skill, discovered on demand. Only
 *   Claude Code ships this today.
 *
 * This enum names the *current, shipped* shapes. Whether a third shape
 * (e.g. Codex's `.agents/skills/<id>/SKILL.md`, proposed but not decided —
 * open question (a) in the #2660 decision memo) becomes canonical is a
 * maintainer decision this enum does not make.
 *
 * @api
 */
enum SkillDeliveryMode
{
    case SingleConsolidatedFile;
    case PerSkillFile;
}
