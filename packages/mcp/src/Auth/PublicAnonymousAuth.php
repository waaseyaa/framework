<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\User\AnonymousUser;

/**
 * Public, read-only MCP authentication: every request resolves to an anonymous
 * account that holds ONLY the configured read capabilities.
 *
 * This is the **capability layer** of the public read-only boundary (the other
 * two being {@see \Waaseyaa\Mcp\ReadOnlyToolRegistry} — write tools are
 * structurally absent — and each tool's own `accessCheck(true)` on every query).
 * Because the anonymous account grants only read capabilities, a write tool's
 * `AbstractAgentTool::requireCapability()` check fails even if it were somehow
 * reached.
 *
 * Both authentication methods always resolve a public principal: an absent or
 * unrecognised `Authorization` header still resolves to the anonymous read
 * account (no 401 for read). An optional {@see McpAuthInterface} delegate is
 * consulted first. Scoped delegates retain their exact credential scopes;
 * legacy unscoped delegates are constrained to this tier's read capabilities.
 * Scopes narrow the registry and never grant the account's tool permissions.
 *
 * @api
 */
final readonly class PublicAnonymousAuth implements ScopedMcpAuthInterface
{
    /** The capabilities granted to anonymous MCP callers. */
    public const array DEFAULT_READ_CAPABILITIES = [
        'tool.entity.read',
        'tool.entity.search',
        'tool.relationship.traverse',
    ];

    /** @var list<string> */
    private array $readCapabilities;

    /**
     * @param list<string>|null $readCapabilities Defaults to the three data-read
     *                                            capabilities above.
     * @param ?McpAuthInterface  $delegate        Optional authenticated-surface
     *                                            auth consulted before the
     *                                            anonymous fallback.
     */
    public function __construct(
        ?array $readCapabilities = null,
        private ?McpAuthInterface $delegate = null,
    ) {
        $this->readCapabilities = $readCapabilities ?? self::DEFAULT_READ_CAPABILITIES;
    }

    public function authenticate(?string $authorizationHeader): AuthorizationPrincipalInterface
    {
        return $this->authenticateWithScopes($authorizationHeader)->account;
    }

    public function authenticateWithScopes(?string $authorizationHeader): ScopedPrincipal
    {
        if ($this->delegate !== null) {
            if ($this->delegate instanceof ScopedMcpAuthInterface) {
                $authenticated = $this->delegate->authenticateWithScopes($authorizationHeader);
                if ($authenticated !== null) {
                    return $authenticated;
                }
            } else {
                $authenticated = $this->delegate->authenticate($authorizationHeader);
                if ($authenticated !== null) {
                    // A legacy unscoped delegate has no narrower credential
                    // scopes to preserve. Constrain it to this public tier's
                    // configured read capabilities instead of treating it as
                    // an unrestricted token.
                    return new ScopedPrincipal($authenticated, $this->readCapabilities);
                }
            }
        }

        return new ScopedPrincipal(
            new AnonymousUser($this->readCapabilities),
            $this->readCapabilities,
        );
    }
}
