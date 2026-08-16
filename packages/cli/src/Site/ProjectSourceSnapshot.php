<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

final readonly class ProjectSourceSnapshot
{
    /** @param array<string, string> $sources */
    public function __construct(public array $sources, public string $digest) {}
}
