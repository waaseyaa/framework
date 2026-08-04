<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Admin;

use Waaseyaa\Api\McpAdmin\RegisteredClient;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigSnapshot;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;

/**
 * MCP-layer adapter implementing the api read contract for the server-config
 * admin surface (M5C WP01 T003).
 *
 * NFR-003 enforcement: plaintext bearer tokens are NEVER stored in the returned
 * snapshot. Each registered client exposes only a 16-character lowercase hex
 * fingerprint derived as `substr(hash('sha256', $token), 0, 16)`.
 */
final class ServerConfigReadModel implements ServerConfigReadModelInterface
{
    public function __construct(
        private readonly McpAuthInterface $auth,
    ) {}

    public function serverConfig(): ServerConfigSnapshot
    {
        $clients = $this->buildRegisteredClients();

        return new ServerConfigSnapshot(
            transport: 'streamable-http',
            protocolVersion: \Waaseyaa\Mcp\McpProtocol::LATEST,
            registeredClients: $clients,
            serverCapabilities: ['tools'],
        );
    }

    /**
     * Build the registered-clients list from the auth adapter.
     *
     * Only `BearerTokenAuth` exposes a token list. Other `McpAuthInterface`
     * implementations (e.g. mTLS, OIDC) return an empty list — they manage
     * their own client registry outside this read model.
     *
     * NFR-003: the plaintext token is hashed and only the first 16 hex chars
     * are returned. The full hash value is never included.
     *
     * @return list<RegisteredClient>
     */
    private function buildRegisteredClients(): array
    {
        if (!($this->auth instanceof BearerTokenAuth)) {
            return [];
        }

        $clients = [];
        foreach ($this->auth->getTokens() as $token => $account) {
            // NFR-003: derive a 16-char fingerprint; never expose plaintext token.
            // getTokens() returns array<string, AccountInterface> (token → account).
            $fingerprint = substr(hash('sha256', $token), 0, 16);

            $clients[] = new RegisteredClient(
                clientId: $fingerprint,
                addedAt: null,
                lastSeenAt: null,
                tokenFingerprint: $fingerprint,
            );
        }

        return $clients;
    }
}
