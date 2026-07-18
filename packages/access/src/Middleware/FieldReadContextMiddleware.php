<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\ContextualAccountPrincipalFactoryInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

/**
 * Installs the immutable principal produced by the audited identity bootstrap
 * after session/bearer identity and before route authorization.
 *
 * @api
 */
#[AsMiddleware(pipeline: 'http', priority: 15)]
final readonly class FieldReadContextMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private AccountPrincipalFactoryInterface $principalFactory,
        private AccountFieldReadScopeInterface $scope,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        $account = $request->attributes->get('_account');
        $tenantId = $this->scopeId($request->attributes->get('tenant_id') ?? $request->attributes->get('_tenant_id'));
        $communityId = $this->scopeId($request->attributes->get('community_id') ?? $request->attributes->get('_community_id'));
        if ($communityId === null) {
            $session = $request->attributes->get('_session');
            $communityId = is_array($session) ? $this->scopeId($session['waaseyaa_community_id'] ?? null) : null;
        }
        $principal = $account instanceof AccountInterface
            ? ($this->principalFactory instanceof ContextualAccountPrincipalFactoryInterface
                ? $this->principalFactory->fromAccountInContext($account, $tenantId, $communityId)
                : $this->principalFactory->fromAccount($account))
            : new AuthorizationPrincipal(0, false, [], [], 'anonymous', $tenantId, $communityId);
        $request->attributes->set('_authorization_principal', $principal);

        $response = $this->scope->run($principal, static fn(): Response => $next->handle($request));
        if ($response instanceof StreamedResponse && ($callback = $response->getCallback()) !== null) {
            $response->setCallback(fn(): mixed => $this->scope->run($principal, $callback));
        }

        return $response;
    }

    private function scopeId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
