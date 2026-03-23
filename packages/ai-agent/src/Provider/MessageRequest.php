<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

final readonly class MessageRequest
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public array $messages,
        public ?string $system = null,
        public array $tools = [],
        public int $maxTokens = 4096,
        public array $metadata = [],
    ) {}

    /**
     * Serialize to Anthropic API request format.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'messages' => $this->messages,
            'max_tokens' => $this->maxTokens,
        ];

        if ($this->system !== null) {
            $data['system'] = $this->system;
        }

        if ($this->tools !== []) {
            $data['tools'] = $this->tools;
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }
}
