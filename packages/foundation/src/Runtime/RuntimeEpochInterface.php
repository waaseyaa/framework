<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Runtime;

/** Process-lifetime state boundary checked between independent work units. @api */
interface RuntimeEpochInterface
{
    /** True means the process must accept no further work under its current composition. */
    public function hasChanged(): bool;

    /** Redaction-safe identity used only for diagnostics. */
    public function fingerprint(): string;
}
