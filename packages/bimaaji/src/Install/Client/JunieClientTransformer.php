<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * JetBrains Junie — consolidated `.junie/guidelines.md` file.
 *
 * Upstream convention: https://www.jetbrains.com/help/junie/
 * (verified 2026-05-22 — Junie loads project guidelines from
 * `.junie/guidelines.md`).
 *
 * @api
 */
final class JunieClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'junie';
    }

    protected function targetPath(): string
    {
        return '.junie/guidelines.md';
    }
}
