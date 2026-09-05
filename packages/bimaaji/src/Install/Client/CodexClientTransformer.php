<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * OpenAI Codex — concise root `AGENTS.md` plus per-skill `.agents/skills/`.
 *
 * Codex reads a plain `AGENTS.md` starting at the git root for always-loaded
 * project guidance. Detailed framework knowledge is installed as on-demand
 * Agent Skills under `.agents/skills/waaseyaa-<id>/SKILL.md`, matching the
 * same canonical inventory Claude Code receives under `.claude/skills/`
 * (#2660).
 *
 * Upstream convention:
 * <https://learn.chatgpt.com/docs/agent-configuration/agents-md>,
 * <https://agents.md> (verified 2026-08-29). Per-skill layout: Codex scans
 * `.agents/skills` in every directory from the current working directory up
 * to the repository root; each skill is a directory whose `SKILL.md` carries
 * `name` and `description` metadata
 * (<https://learn.chatgpt.com/docs/build-skills#where-codex-loads-local-skills>,
 * verified 2026-09-05).
 *
 * @api
 */
final class CodexClientTransformer extends AbstractPerSkillClientTransformer
{
    public function clientId(): string
    {
        return 'codex';
    }

    protected function guidanceTitle(): string
    {
        return '# Waaseyaa Agent Adapter';
    }
}
