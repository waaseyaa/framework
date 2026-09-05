<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * Multi-file transformer for Claude Code.
 *
 * A Claude Code **project skill is a directory**, not a file:
 * `.claude/skills/<skill-name>/SKILL.md`. The command a user types comes from
 * the *directory* name; the frontmatter `name` is only the display label shown
 * in skill listings.
 *
 * Concise guidance lives in `.claude/CLAUDE-WAASEYAA.md` — a marker-bounded
 * include separate from the consumer's hand-authored root `CLAUDE.md`, so
 * install refreshes do not overwrite application guidance (#2660).
 *
 * Upstream convention: <https://code.claude.com/docs/en/skills> §"Where skills
 * live" (`Project | .claude/skills/<skill-name>/SKILL.md`) and §"How a skill
 * gets its command name" (verified 2026-08-29).
 *
 * @api
 */
final class ClaudeClientTransformer extends AbstractPerSkillClientTransformer
{
    public function clientId(): string
    {
        return 'claude';
    }

    protected function guidanceTitle(): string
    {
        return '# Waaseyaa framework — Claude Code guidelines';
    }
}
