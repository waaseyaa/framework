<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * Multi-file transformer for Claude Code.
 *
 * A Claude Code **project skill is a directory**, not a file:
 * `.claude/skills/<skill-name>/SKILL.md`. The command a user types comes from
 * the *directory* name; the frontmatter `name` is only the display label shown
 * in skill listings. A flat `.claude/skills/<name>.md` is not a documented
 * layout and is not discovered at all — which is what this transformer emitted
 * until #2656 follow-up review, so every "written" count it reported was
 * producing files Claude Code would never load.
 *
 * We therefore emit one directory per skill
 * (`.claude/skills/waaseyaa-<id>/SKILL.md`) plus one consolidated guidelines
 * page (`.claude/CLAUDE-WAASEYAA.md`) that lists each skill — kept as a
 * *separate* file from a consumer's own hand-authored root `CLAUDE.md`, so a
 * re-run never touches application guidance the consumer wrote themselves.
 * The directory-per-skill shape also matches the way the canonical set ships
 * inside this package (`resources/skills/<id>/SKILL.md`), so the install is a
 * structural rename rather than a flattening.
 *
 * Both outputs are marker-bounded (see {@see \Waaseyaa\Bimaaji\Install\ManagedRegion}).
 * For a per-skill file the markers open *after* the YAML frontmatter, because
 * Claude Code reads frontmatter "only when the opening `---` is the file's
 * first line" — so the skill body refreshes on every run while a consumer's
 * own `name`/`description` edits, and any notes they append below the
 * closing marker, survive. A skill directory may also hold supporting files
 * the user added; the installer never removes those.
 *
 * Rendering (per-skill file shape, frontmatter gating, the guidance index,
 * and the source-inventory provenance footer) lives in the shared
 * {@see AbstractPerSkillClientTransformer}, which Codex now shares (#2660
 * Part B) — see that class for the rendering contract.
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
