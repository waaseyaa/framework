<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * OpenAI Codex — concise root `AGENTS.md` plus per-skill `.agents/skills/`
 * (#2660 Part B).
 *
 * Codex reads a plain `AGENTS.md` starting at the git root for always-loaded
 * project guidance — vendor-neutral per <https://agents.md> and
 * <https://learn.chatgpt.com/docs/agent-configuration/agents-md> (verified
 * 2026-08-29), and the same file Devin Desktop and JetBrains Junie read
 * (root `AGENTS.md`'s shared ownership across those clients is tracked
 * separately by #2686, not decided here). Detailed framework knowledge is
 * installed as on-demand Agent Skills under
 * `.agents/skills/waaseyaa-<id>/SKILL.md`, matching the same canonical
 * inventory Claude Code receives under `.claude/skills/` — rendering is
 * shared via {@see AbstractPerSkillClientTransformer}.
 *
 * This transformer previously wrote every skill body folded into `AGENTS.md`
 * (`ClientCapabilityRegistry` recorded `codex => SingleConsolidatedFile`).
 * That changes here on the strength of a verified, citable Codex-side
 * discovery mechanism for a per-skill directory: OpenAI documents repository
 * `.agents/skills` discovery walking from the current working directory up
 * to the repository root, with each skill directory's `SKILL.md` carrying
 * `name`/`description` metadata, at
 * <https://learn.chatgpt.com/docs/build-skills#where-codex-loads-local-skills>
 * (verified 2026-09-05). Writing a layout a client's own convention does not
 * discover is exactly the defect #2656 fixed twice already (Claude's flat
 * `.claude/skills/waaseyaa-<id>.md` and Codex's own `.codex/AGENTS.md`); this
 * layout ships only with the same class of first-party citation #2656
 * required, not on an issue-body sketch alone. See
 * `docs/adr/026-client-guidance-and-skill-conventions.md` (a). The accepted
 * (a)-(c) decision records the root integrator's delegated technical authority;
 * governed landing still depends on the repository's review and qualification.
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
        return '# Waaseyaa framework — Codex guidelines';
    }
}
