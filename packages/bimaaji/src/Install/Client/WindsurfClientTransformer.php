<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * Windsurf — consolidated `.windsurfrules` file at the project root.
 *
 * Upstream convention: https://docs.codeium.com/windsurf/rules
 * (verified 2026-05-22 — Windsurf reads workspace rules from `.windsurfrules`).
 *
 * @api
 */
final class WindsurfClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'windsurf';
    }

    protected function targetPath(): string
    {
        return '.windsurfrules';
    }
}
