<?php

declare(strict_types=1);

namespace Waaseyaa\Api\AiObservability\Runs;

/**
 * Filter criteria for the runs list endpoint.
 *
 * @api
 */
final readonly class RunListFilter
{
    public function __construct(
        public ?string $pipeline,
        public ?string $status,
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
    ) {}

    /**
     * Parse and clamp filter values from a raw query parameter array.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $pipeline = isset($query['pipeline']) && is_string($query['pipeline']) && $query['pipeline'] !== ''
            ? $query['pipeline']
            : null;

        $status = isset($query['status']) && is_string($query['status']) && $query['status'] !== ''
            ? $query['status']
            : null;

        $from = null;
        if (isset($query['from']) && is_string($query['from']) && $query['from'] !== '') {
            try {
                $from = new \DateTimeImmutable($query['from']);
            } catch (\Exception) {
                $from = null;
            }
        }

        $to = null;
        if (isset($query['to']) && is_string($query['to']) && $query['to'] !== '') {
            try {
                $to = new \DateTimeImmutable($query['to']);
            } catch (\Exception) {
                $to = null;
            }
        }

        return new self(
            pipeline: $pipeline,
            status: $status,
            from: $from,
            to: $to,
        );
    }
}
