<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit;

/**
 * What a caller is about to attempt, recorded durably BEFORE it is attempted.
 *
 * Every field here is safe to persist. The construction sites are responsible
 * for that — in particular `$safeArguments` must already have passed through
 * the tool's own redaction transform (`argumentsForAudit()`), never the raw
 * JSON-RPC params. This object carries no credential, no Authorization header,
 * no exception detail, and no unredacted content.
 *
 * @api
 */
final readonly class StrictAuditReservation
{
    /**
     * @param string               $correlationId Joins every record for one request, and is
     *                                            returned to the caller for diagnostics.
     * @param string               $surface       The calling surface, e.g. `mcp.write`. Lets one
     *                                            ledger serve more than one entry point.
     * @param string               $operation     What is being attempted — for MCP, the tool name.
     * @param ?int                 $actorUid      Three-state actor, matching the OCAP convention:
     *                                            id N, `0` only when the actor IS anonymous, or
     *                                            `null` for "no known principal". Never coerce.
     * @param array<string, mixed> $safeArguments Already-redacted arguments. MUST NOT be raw params.
     * @param array<string, mixed> $metadata      Additional safe structural metadata.
     */
    public function __construct(
        public string $correlationId,
        public string $surface,
        public string $operation,
        public ?int $actorUid,
        public array $safeArguments = [],
        public array $metadata = [],
    ) {
        if ($correlationId === '') {
            throw new \InvalidArgumentException('A strict audit reservation requires a correlation id.');
        }
        if ($surface === '') {
            throw new \InvalidArgumentException('A strict audit reservation requires a surface.');
        }
        if ($operation === '') {
            throw new \InvalidArgumentException('A strict audit reservation requires an operation.');
        }
    }
}
