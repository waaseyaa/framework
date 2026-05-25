<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\Consent\ConsentScreenController;
use Waaseyaa\Oidc\Revoke\RevocationController;
use Waaseyaa\Oidc\Token\TokenController;
use Waaseyaa\Oidc\Userinfo\UserinfoController;

/**
 * OIDC HTTP route table. Lives in the routing package (L4) so L1 oidc is free of
 * RouteBuilder / WaaseyaaRouter type references.
 *
 * Routes use /oidc/* prefix for all issuer-owned endpoints.
 * Discovery + JWKS live at /.well-known/* per spec.
 */
final readonly class OidcHttpRoutes
{
    public function __construct(
        private ?AuthorizeController $authorizeController = null,
        private ?TokenController $tokenController = null,
        private ?RevocationController $revocationController = null,
        private ?UserinfoController $userinfoController = null,
        private ?ConsentScreenController $consentScreenController = null,
    ) {}

    public function registerRoutes(WaaseyaaRouter $router): void
    {
        // Discovery + JWKS — new namespaced controllers (WP04)
        $router->addRoute(
            'oidc.discovery',
            RouteBuilder::create('/.well-known/openid-configuration')
                ->controller('Waaseyaa\\Oidc\\Discovery\\DiscoveryController::__invoke')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );

        $router->addRoute(
            'oidc.jwks',
            RouteBuilder::create('/.well-known/jwks.json')
                ->controller('Waaseyaa\\Oidc\\Jwks\\JwksController::__invoke')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );

        // Authorization code endpoint (WP01)
        if ($this->authorizeController !== null) {
            $router->addRoute(
                'oidc.authorize',
                RouteBuilder::create('/oidc/authorize')
                    ->controller($this->authorizeController)
                    ->methods('GET')
                    ->allowAll()
                    ->build(),
            );
        }

        // Token endpoint (WP01 + WP02)
        if ($this->tokenController !== null) {
            $router->addRoute(
                'oidc.token',
                RouteBuilder::create('/oidc/token')
                    ->controller($this->tokenController)
                    ->methods('POST')
                    ->allowAll()
                    ->csrfExempt()
                    ->build(),
            );
        }

        // Revocation endpoint (WP02)
        if ($this->revocationController !== null) {
            $router->addRoute(
                'oidc.revoke',
                RouteBuilder::create('/oidc/revoke')
                    ->controller($this->revocationController)
                    ->methods('POST')
                    ->allowAll()
                    ->csrfExempt()
                    ->build(),
            );
        }

        // Userinfo endpoint (WP03)
        if ($this->userinfoController !== null) {
            $router->addRoute(
                'oidc.userinfo',
                RouteBuilder::create('/oidc/userinfo')
                    ->controller($this->userinfoController)
                    ->methods('GET', 'POST')
                    ->allowAll()
                    ->csrfExempt()
                    ->build(),
            );
        }

        // Consent screen (WP05)
        if ($this->consentScreenController !== null) {
            $router->addRoute(
                'oidc.consent',
                RouteBuilder::create('/oidc/consent')
                    ->controller($this->consentScreenController)
                    ->methods('GET', 'POST')
                    ->allowAll()
                    ->build(),
            );
        }
    }
}
