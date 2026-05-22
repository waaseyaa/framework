<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * Google Gemini CLI — consolidated `GEMINI.md` at the project root.
 *
 * Upstream convention: https://github.com/google-gemini/gemini-cli
 * (verified 2026-05-22 — Gemini CLI loads context from `GEMINI.md`).
 *
 * @api
 */
final class GeminiClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'gemini';
    }

    protected function targetPath(): string
    {
        return 'GEMINI.md';
    }
}
