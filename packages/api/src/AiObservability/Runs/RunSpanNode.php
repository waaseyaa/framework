<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * A node in the span tree for a run detail.
 *
 * @api
 */
final readonly class RunSpanNode
{
    /**
     * @param array<string, mixed> $attributes
     * @param list<RunSpanNode>    $children
     */
    public function __construct(
        public string $spanUuid,
        public ?string $parentSpanUuid,
        public string $kind,
        public string $name,
        public string $status,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationMs,
        public array $attributes,
        public array $children,
        public bool $truncated,
    ) {}
}
