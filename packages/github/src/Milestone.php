<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final readonly class Milestone
{
    public function __construct(
        public int $number,
        public string $title,
        public string $description,
        public string $state,
        public int $openIssues,
        public int $closedIssues,
    ) {}
}
