<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\ClientRegistry;

/** Exact non-secret registration data used by first-party OIDC protocol flows. @api */
final readonly class OidcClientRegistration
{
    /** @param list<string> $redirectUris @param list<string> $scopes @param list<string> $grantTypes */
    public function __construct(
        public string $name,
        public array $redirectUris,
        public array $scopes,
        public array $grantTypes,
        public bool $confidential,
    ) {}

    public function hasRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }
}
