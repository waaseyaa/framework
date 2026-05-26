<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * Snapshot of the MCP server configuration (M5C WP01 T001).
 *
 * Returned by {@see ServerConfigReadModelInterface::serverConfig()}.
 *
 * NFR-003: no plaintext token is included. Clients appear only via
 * {@see RegisteredClient::$tokenFingerprint} (16-char hex, SHA-256 prefix).
 *
 * @api
 */
final readonly class ServerConfigSnapshot
{
    /**
     * @param list<RegisteredClient> $registeredClients
     * @param list<string>           $serverCapabilities E.g. `['tools']`
     */
    public function __construct(
        /** @var 'streamable-http'|'sse' */
        public string $transport,
        public string $protocolVersion,
        public array $registeredClients,
        public array $serverCapabilities,
    ) {}
}
