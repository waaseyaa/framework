<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class MessageResponse
{
    /**
     * @param array<int, array<string, mixed>> $content Content blocks (text, tool_use)
     * @param array{input_tokens: int, output_tokens: int, cache_creation_input_tokens?: int, cache_read_input_tokens?: int} $usage
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public array $usage = [],
    ) {}

    /**
     * Extract text content from the response.
     */
    public function getText(): string
    {
        $text = '';
        foreach ($this->content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        return $text;
    }

    /**
     * Extract tool use blocks from the response.
     *
     * @return ToolUseBlock[]
     */
    public function getToolUseBlocks(): array
    {
        $blocks = [];
        foreach ($this->content as $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $blocks[] = new ToolUseBlock(
                    id: $block['id'],
                    name: $block['name'],
                    input: $block['input'] ?? [],
                );
            }
        }
        return $blocks;
    }
}
