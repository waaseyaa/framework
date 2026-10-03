<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
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

    /** @return iterable<RouteDefinition> Finalized binding presence, never controller health. */
    public static function routeDefinitions(RouteContributionContext $context, int $ordinal = 0): iterable
    {
        foreach ([
            ['oidc.discovery', '/.well-known/openid-configuration', 'Waaseyaa\\Oidc\\Discovery\\DiscoveryController', ['GET'], false],
            ['oidc.jwks', '/.well-known/jwks.json', 'Waaseyaa\\Oidc\\Jwks\\JwksController', ['GET'], false],
            ['oidc.authorize', '/oidc/authorize', 'Waaseyaa\\Oidc\\Authorize\\AuthorizeController', ['GET'], false],
            ['oidc.token', '/oidc/token', 'Waaseyaa\\Oidc\\Token\\TokenController', ['POST'], true],
            ['oidc.revoke', '/oidc/revoke', 'Waaseyaa\\Oidc\\Revoke\\RevocationController', ['POST'], true],
            ['oidc.userinfo', '/oidc/userinfo', 'Waaseyaa\\Oidc\\Userinfo\\UserinfoController', ['GET', 'POST'], true],
            ['oidc.consent', '/oidc/consent', 'Waaseyaa\\Oidc\\Consent\\ConsentScreenController', ['GET', 'POST'], false],
        ] as [$name, $path, $controller, $methods, $csrfExempt]) {
            if (($context->capabilities['service:' . $controller] ?? false) !== true) {
                continue;
            }
            $options = ['_public' => true];
            if ($csrfExempt) {
                $options['_csrf'] = false;
            }
            yield new RouteDefinition($name, $path, HandlerReference::fromString('class:' . $controller . '::__invoke'), methods: $methods, options: $options, sourceId: $context->sourceId, ordinal: $ordinal++);
        }
    }

    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $controllers = [
            'Waaseyaa\\Oidc\\Discovery\\DiscoveryController' => 'Waaseyaa\\Oidc\\Discovery\\DiscoveryController::__invoke',
            'Waaseyaa\\Oidc\\Jwks\\JwksController' => 'Waaseyaa\\Oidc\\Jwks\\JwksController::__invoke',
            'Waaseyaa\\Oidc\\Authorize\\AuthorizeController' => $this->authorizeController,
            'Waaseyaa\\Oidc\\Token\\TokenController' => $this->tokenController,
            'Waaseyaa\\Oidc\\Revoke\\RevocationController' => $this->revocationController,
            'Waaseyaa\\Oidc\\Userinfo\\UserinfoController' => $this->userinfoController,
            'Waaseyaa\\Oidc\\Consent\\ConsentScreenController' => $this->consentScreenController,
        ];
        $capabilities = [];
        foreach ($controllers as $id => $controller) {
            $capabilities['service:' . $id] = $controller !== null;
        }
        foreach (self::routeDefinitions(new RouteContributionContext(self::class, 0, capabilities: $capabilities)) as $definition) {
            $route = new RouteMetadataCompiler()->compileRoute($definition);
            $options = $route->getOptions();
            unset($options['_waaseyaa_priority']);
            $route->setOptions($options);
            $route->setDefault('_controller', $controllers[$definition->handler->target]);
            $router->addRoute($definition->name, $route);
        }
    }
}
