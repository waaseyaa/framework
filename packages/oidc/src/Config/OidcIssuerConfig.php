<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Config;

/**
 * Carries the resolved OIDC issuer URL for injection across the package.
 *
 * Resolved once in OidcServiceProvider::register() from config / env / default.
 *
 * @api
 */
final readonly class OidcIssuerConfig
{
    public function __construct(
        public string $issuerUrl,
    ) {}
}
