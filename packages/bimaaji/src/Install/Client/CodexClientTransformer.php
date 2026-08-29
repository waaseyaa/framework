<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * OpenAI Codex — consolidated `AGENTS.md` at the repository root.
 *
 * This transformer previously wrote `.codex/AGENTS.md`, which is not a Codex
 * discovery location at all: it conflated the *project* scope with the
 * *personal* one. Codex reads a plain `AGENTS.md` starting at the git root and
 * walking down to the working directory (one file per directory, closer files
 * overriding), while `~/.codex/AGENTS.md` under `$CODEX_HOME` is the separate
 * global scope that lives in the user's home directory, never inside the
 * target repository. A `.codex/AGENTS.md` inside a project was read by
 * nothing.
 *
 * The root `AGENTS.md` is deliberately shared rather than vendor-private: it
 * is the vendor-neutral <https://agents.md> file that Codex, Devin Desktop and
 * JetBrains Junie all read. Writing it is safe because the payload is
 * marker-bounded — a project that already has a hand-authored `AGENTS.md`
 * keeps every byte outside the markers, and a file with no markers still
 * requires `--force` or an interactive confirmation.
 *
 * Upstream convention:
 * <https://learn.chatgpt.com/docs/agent-configuration/agents-md> (the
 * canonical target of `developers.openai.com/codex/guides/agents-md`) and
 * <https://agents.md> (verified 2026-08-29).
 *
 * @api
 */
final class CodexClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'codex';
    }

    protected function targetPath(): string
    {
        return 'AGENTS.md';
    }
}
