<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * GitHub Codex CLI — consolidated `.codex/AGENTS.md` file.
 *
 * Upstream convention: https://github.com/openai/codex
 * (verified 2026-05-22 — Codex CLI reads project guidance from
 * `.codex/AGENTS.md`).
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
        return '.codex/AGENTS.md';
    }
}
