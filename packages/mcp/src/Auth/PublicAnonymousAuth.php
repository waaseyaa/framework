<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

use Waaseyaa\Access\AccountInterface;
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
 * `authenticate()` never returns `null` for the public surface: an absent or
 * unrecognised `Authorization` header still resolves to the anonymous read
 * account (no 401 for read). An optional {@see McpAuthInterface} delegate is
 * consulted first so a future authenticated surface can compose on top without
 * weakening the anonymous fallback.
 *
 * @api
 */
final readonly class PublicAnonymousAuth implements McpAuthInterface
{
    /** The capabilities granted to anonymous MCP callers. */
    public const array DEFAULT_READ_CAPABILITIES = [
        'tool.entity.read',
        'tool.entity.search',
        'tool.relationship.traverse',
        'bimaaji.read',
    ];

    /** @var list<string> */
    private array $readCapabilities;

    /**
     * @param list<string>|null $readCapabilities Defaults to the four read
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

    public function authenticate(?string $authorizationHeader): AccountInterface
    {
        if ($this->delegate !== null) {
            $authenticated = $this->delegate->authenticate($authorizationHeader);
            if ($authenticated !== null) {
                return $authenticated;
            }
        }

        return new AnonymousUser($this->readCapabilities);
    }
}
