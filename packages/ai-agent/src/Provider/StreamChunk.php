<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class StreamChunk
{
    public function __construct(
        public string $type,
        public string $text = '',
        public ?ToolUseBlock $toolUse = null,
    ) {}
}
