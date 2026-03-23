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

    /**
     * @return array{type: string, tool_use_id: string, content: string, is_error: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $this->toolUseId,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }
}
