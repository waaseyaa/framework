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
 * <https://agents.md> (verified 2026-08-29). Per-skill layout follows the
 * Agent Skills directory convention shared with Claude Code and Codex CLI
 * tooling (verified in packaged-consumer acceptance, #2660).
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
