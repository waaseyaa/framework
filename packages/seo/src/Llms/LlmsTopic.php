<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Llms;

/**
 * A single topic in an `llms.txt` index: a titled grouping of per-topic
 * Markdown URLs an agent can fetch.
 *
 * @api
 */
final readonly class LlmsTopic
{
    /**
     * @param list<array{title: string, url: string}> $links Per-topic Markdown URLs.
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $summary,
        public array $links,
    ) {}
}
