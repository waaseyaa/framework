<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Auth;

/**
 * RFC 9728 protected-resource metadata for one OAuth-protected MCP endpoint.
 *
 * The resource and authorization-server identifiers are absolute HTTPS URIs;
 * plain HTTP is accepted only for loopback development. The object also owns
 * the exact metadata URI used in `WWW-Authenticate`, preventing the challenge
 * and discovery document from drifting apart.
 *
 * @api
 */
final readonly class OAuthProtectedResourceMetadataConfig
{
    /**
     * @param list<string> $authorizationServers
     * @param list<string> $scopesSupported
     */
    public function __construct(
        public string $resource,
        public array $authorizationServers,
        public array $scopesSupported = [],
        public ?string $resourceDocumentation = null,
    ) {
        self::assertSecureAbsoluteUri($this->resource, 'resource');
        if ($this->authorizationServers === []) {
            throw new \InvalidArgumentException('authorization_servers must be a non-empty list.');
        }
        foreach ($this->authorizationServers as $server) {
            self::assertSecureAbsoluteUri($server, 'authorization_servers');
        }
        if (\count(\array_unique($this->authorizationServers)) !== \count($this->authorizationServers)) {
            throw new \InvalidArgumentException('authorization_servers must not contain duplicates.');
        }
        foreach ($this->scopesSupported as $scope) {
            if ($scope === '' || \preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope) !== 1) {
                throw new \InvalidArgumentException('scopes_supported contains an invalid OAuth scope token.');
            }
        }
        if (\count(\array_unique($this->scopesSupported)) !== \count($this->scopesSupported)) {
            throw new \InvalidArgumentException('scopes_supported must not contain duplicates.');
        }
        if ($this->resourceDocumentation !== null) {
            self::assertSecureAbsoluteUri($this->resourceDocumentation, 'resource_documentation');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return \array_filter([
            'resource' => $this->resource,
            'authorization_servers' => $this->authorizationServers,
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => $this->scopesSupported !== [] ? $this->scopesSupported : null,
            'resource_documentation' => $this->resourceDocumentation,
        ], static fn(mixed $value): bool => $value !== null);
    }

    public function metadataPath(): string
    {
        $path = (string) (\parse_url($this->resource, \PHP_URL_PATH) ?? '');

        return '/.well-known/oauth-protected-resource' . ($path === '/' ? '' : $path);
    }

    public function metadataUri(): string
    {
        $parts = \parse_url($this->resource);
        \assert(\is_array($parts));
        $authority = $parts['scheme'] . '://' . self::formatHost($parts['host']);
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority . $this->metadataPath();
    }

    public function challenge(): string
    {
        $challenge = 'Bearer resource_metadata="' . $this->metadataUri() . '"';
        if ($this->scopesSupported !== []) {
            $challenge .= ', scope="' . \implode(' ', $this->scopesSupported) . '"';
        }

        return $challenge;
    }

    private static function assertSecureAbsoluteUri(string $uri, string $field): void
    {
        $parts = \parse_url($uri);
        if (!\is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            // Each component is rejected on its own. A single multi-argument
            // isset() is a conjunction — it fires only when ALL FOUR are
            // present — so a resource carrying just a query or just userinfo
            // passed, and metadataUri() rebuilds the authority without it, so
            // the advertised discovery URI and the audience handed to
            // OAuthAccessTokenValidatorInterface::validate() would disagree.
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !\in_array(\strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \InvalidArgumentException($field . ' must be an absolute HTTP(S) URI without credentials, query, or fragment.');
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);
        if ($scheme !== 'https' && !\in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new \InvalidArgumentException($field . ' must use HTTPS except on a loopback host.');
        }
    }

    private static function formatHost(string $host): string
    {
        return \str_contains($host, ':') ? '[' . $host . ']' : $host;
    }
}
