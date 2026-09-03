<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Mcp\Stdio;

/**
 * Protocol revisions the local stdio transport can negotiate on `initialize`.
 *
 * **Every revision listed here is handshake-era, and that is the whole point.**
 * MCP splits its revisions into two eras that cannot be mixed: revisions
 * `2025-11-25` and earlier ("legacy") open a session with an `initialize`
 * request and a `notifications/initialized` acknowledgement; revision
 * `2026-07-28` and later ("modern") have no handshake at all — "There is no
 * negotiation handshake. Every request carries its protocol version, and the
 * server accepts or rejects each request independently" — carry the version,
 * client capabilities, and client identity in each request's
 * `_meta.io.modelcontextprotocol/*`, and MUST implement `server/discover`
 * (MCP `2026-07-28`, "Versioning and Compatibility", §Protocol Version
 * Negotiation and §Terminology).
 *
 * {@see StdioMcpServer} implements the handshake lifecycle, so it is a legacy
 * server and says so: the newest revision it will ever negotiate is
 * {@see LATEST_HANDSHAKE_REVISION}. Advertising `2026-07-28` from an
 * `initialize` result would claim a per-request-metadata surface this
 * transport does not implement — the client would then be entitled to send
 * `_meta`-bearing requests and to expect `server/discover`, and would get
 * `-32601` for its trouble.
 *
 * That is also exactly how a dual-era client is specified to discover us: it
 * "SHOULD probe with `server/discover` before sending any other request", and
 * "returns any other error … the server is legacy. Fall back to the
 * `initialize` handshake" (MCP `2026-07-28`, "stdio", §Backward
 * Compatibility). {@see StdioMcpServer}'s `-32601` for `server/discover` is
 * that signal, and the fallback "MUST NOT be keyed to one specific error
 * code", so it is a stable one. Answering `server/discover`, or returning a
 * recognized modern error such as `-32022 UnsupportedProtocolVersionError`,
 * would misidentify this server as modern and stop the client from falling
 * back — so this transport deliberately does neither.
 *
 * Deliberately independent of `Waaseyaa\Mcp\McpProtocol`: `waaseyaa/mcp`
 * registers `/mcp` and `/mcp/write` unconditionally the moment it is
 * installed (ADR-022 C-4), so this transport must not require that package
 * merely to borrow a handful of version strings — D-1.4 and D-9.3 both forbid
 * an HTTP-package dependency here. The list below is deliberately identical
 * to that class's `LEGACY_SUPPORTED` (its own `initialize` negotiates from
 * exactly these three revisions and never from `CURRENT`), kept in step by
 * hand rather than by import; this stdio implementation deliberately omits
 * `2025-03-26`, whose normative base protocol requires receivers to accept
 * JSON-RPC batches. A version bump to one is not automatically a bump to the
 * other. The HTTP tiers additionally serve the modern era on the
 * same endpoint — a dual-era server — which is why `McpProtocol::SUPPORTED`
 * is wider than this list. This transport is single-era on purpose.
 *
 * @api
 */
final class StdioMcpProtocol
{
    /**
     * The newest MCP revision that still defines the `initialize` handshake,
     * and therefore the newest one this transport can honestly speak.
     */
    public const string LATEST_HANDSHAKE_REVISION = '2025-11-25';

    /**
     * Every handshake-era revision, newest first.
     *
     * @var non-empty-list<string>
     */
    public const array SUPPORTED = [
        self::LATEST_HANDSHAKE_REVISION,
        '2025-06-18',
    ];

    /**
     * Negotiate a protocol version for `initialize`.
     *
     * A client requesting a revision this server knows gets it back verbatim;
     * anything else — an unknown revision, or a modern-era one such as
     * `2026-07-28` whose lifecycle this transport does not implement — gets
     * {@see LATEST_HANDSHAKE_REVISION}, so the client can compare it against
     * what it requested and decide whether to continue or disconnect. That is
     * the legacy contract exactly: "If the server supports the requested
     * protocol version, it MUST respond with the same version. Otherwise, the
     * server MUST respond with another protocol version it supports."
     */
    public static function negotiate(string $requested): string
    {
        return \in_array($requested, self::SUPPORTED, true) ? $requested : self::LATEST_HANDSHAKE_REVISION;
    }
}
