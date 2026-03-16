<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final readonly class Issue
{
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public string $state,
        public ?string $milestone,
        public array $labels,
        public array $assignees,
    ) {}
}
