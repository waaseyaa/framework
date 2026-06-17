<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Io;

interface StdinSource
{
    public function readLine(): ?string;

    public function isInteractive(): bool;
}
