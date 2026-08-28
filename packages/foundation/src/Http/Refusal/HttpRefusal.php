<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Refusal;

/**
 * One kernel-level request refusal, expressed independently of the wire
 * vocabulary it will be rendered in.
 *
 * The kernel refuses some requests before the matched controller ever runs —
 * an oversized body, a malformed JSON document. Those refusals were hard-coded
 * to the framework's JSON:API error envelope, which made them unreadable to a
 * client that had been promised a different transport by the very endpoint it
 * was calling (see {@see RefusalEnvelope}).
 *
 * A refusal therefore carries both halves: the JSON:API `title`/`detail` pair,
 * and the neutral `transportMessage`/`transportData` pair another transport
 * needs. `reason` is the stable key a route maps to its own error code.
 *
 * @api
 */
final readonly class HttpRefusal
{
    /**
     * @param int                   $status           HTTP status for the refusal.
     * @param string                $reason           Stable reason key — see the `REASON_*`
     *                                                constants on {@see RefusalEnvelope}.
     * @param string                $title            JSON:API `errors[].title`.
     * @param string|null           $detail           JSON:API `errors[].detail`, omitted when null.
     * @param string|null           $transportMessage Message for a non-JSON:API transport;
     *                                                falls back to `$detail`, then `$title`.
     * @param array<string, scalar> $transportData    Structured context for a non-JSON:API
     *                                                transport; omitted when empty.
     */
    public function __construct(
        public int $status,
        public string $reason,
        public string $title,
        public ?string $detail = null,
        public ?string $transportMessage = null,
        public array $transportData = [],
    ) {}

    /** The human-readable message a non-JSON:API transport should carry. */
    public function message(): string
    {
        return $this->transportMessage ?? $this->detail ?? $this->title;
    }
}
