<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools;

/**
 * Result of an {@see AgentToolInterface::execute()} or
 * {@see AgentToolInterface::dryRun()} call.
 *
 * Shape mirrors the MCP `tools/call` response contract (a list of
 * content blocks plus an optional `isError` flag). The optional
 * `summary` is a human-readable one-line description used by the
 * agent transcript and audit log.
 *
 * @api
 */
final readonly class AgentToolResult
{
    /**
     * @param array<int, array{type: string, text?: string, data?: mixed}> $content
     */
    public function __construct(
        public bool $isError,
        public array $content,
        public ?string $summary = null,
        /** @var ?array<string, mixed> */
        public ?array $structuredContent = null,
    ) {}

    /**
     * @param array<int, array{type: string, text?: string, data?: mixed}> $content
     */
    public static function success(array $content, ?string $summary = null, ?array $structuredContent = null): self
    {
        return new self(
            isError: false,
            content: $content,
            summary: $summary,
            structuredContent: $structuredContent,
        );
    }

    public static function error(string $message, ?string $summary = null): self
    {
        return new self(
            isError: true,
            content: [['type' => 'text', 'text' => $message]],
            summary: $summary ?? $message,
        );
    }
}
