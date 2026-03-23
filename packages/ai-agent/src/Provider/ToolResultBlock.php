<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class ToolResultBlock
{
    public function __construct(
        public string $toolUseId,
        public string $content,
        public bool $isError = false,
    ) {}
}
