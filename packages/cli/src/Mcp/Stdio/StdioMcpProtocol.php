<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Mcp\Stdio;

/**
 * Protocol revisions the local stdio transport can negotiate on `initialize`.
 *
 * Deliberately independent of `Waaseyaa\Mcp\McpProtocol`: `waaseyaa/mcp`
 * registers `/mcp` and `/mcp/write` unconditionally the moment it is
 * installed (ADR-022 C-4), so this transport must not require that package
 * merely to borrow a handful of version strings — D-1.4 and D-9.3 both forbid
 * an HTTP-package dependency here. The wire values below are the same
 * revisions the HTTP tiers advertise, kept identical by hand rather than by
 * import; a version bump to one is not automatically a bump to the other.
 *
 * @api
 */
final class StdioMcpProtocol
{
    public const string CURRENT = '2026-07-28';

    /** @var non-empty-list<string> */
    public const array SUPPORTED = [
        self::CURRENT,
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
    ];

    /**
     * Negotiate a protocol version for `initialize`.
     *
     * A client requesting a revision this server knows gets it back verbatim;
     * anything else — an unknown or future revision — gets the current
     * revision, so the client can compare it against what it requested and
     * decide whether to continue or disconnect, per the MCP version
     * negotiation contract.
     */
    public static function negotiate(string $requested): string
    {
        return \in_array($requested, self::SUPPORTED, true) ? $requested : self::CURRENT;
    }
}
