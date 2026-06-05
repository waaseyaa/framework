<?php

declare(strict_types=1);

namespace Waaseyaa\Api\McpAdmin;

/**
 * A registered MCP client entry in the server-config snapshot (M5C WP01 T001).
 *
 * NFR-003: no plaintext token is ever stored or returned. The `tokenFingerprint`
 * is the first 16 hex chars of SHA-256(`token`) — enough for correlation without
 * exposing the secret.
 *
 * @api
 */
final readonly class RegisteredClient
{
    public function __construct(
        public string $clientId,
        public ?string $addedAt,
        public ?string $lastSeenAt,
        /** 16-character lowercase hex fingerprint of the client bearer token. */
        public string $tokenFingerprint,
    ) {}
}
