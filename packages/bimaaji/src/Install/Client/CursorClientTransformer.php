<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

/**
 * Cursor — consolidated `.cursorrules` file at the project root.
 *
 * Upstream convention: https://docs.cursor.com/context/rules-for-ai
 * (verified 2026-05-22 — Cursor reads `.cursorrules` from the workspace root).
 *
 * @api
 */
final class CursorClientTransformer extends AbstractSingleFileClientTransformer
{
    public function clientId(): string
    {
        return 'cursor';
    }

    protected function targetPath(): string
    {
        return '.cursorrules';
    }
}
