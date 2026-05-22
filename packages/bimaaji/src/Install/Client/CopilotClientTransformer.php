<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * GitHub Copilot — consolidated `.github/copilot-instructions.md`.
 *
 * Upstream convention: https://docs.github.com/en/copilot/customizing-copilot/adding-custom-instructions-for-github-copilot
 * (verified 2026-05-22 — Copilot reads workspace instructions from
 * `.github/copilot-instructions.md`).
 *
 * @api
 */
final class CopilotClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'copilot';
    }

    protected function targetPath(): string
    {
        return '.github/copilot-instructions.md';
    }
}
