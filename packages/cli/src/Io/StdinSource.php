<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Io;

/**
 * Prompt input abstraction a consumer can implement to drive interactive
 * commands from a scripted or non-TTY source.
 *
 * Declared `public` in `packages/cli/public-surface.php`: it is an extension
 * point for downstream implementors, so it has no first-party caller inside
 * `src/` by design.
 *
 * @api
 */
interface StdinSource
{
    public function readLine(): ?string;

    public function isInteractive(): bool;
}
