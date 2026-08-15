<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Jwks;

use Symfony\Component\HttpFoundation\JsonResponse;
use Waaseyaa\Oidc\Key\SigningKeyLifecyclePolicy;
use Waaseyaa\Oidc\Token\KeyMaterialProviderInterface;

/**
 * GET /.well-known/jwks.json — RFC 7517 JWKS endpoint.
 *
 * Returns public keys for current + previous signing keys.
 * Cache-Control: public, max-age=86400 (consumers cache aggressively).
 *
 * @api
 */
final readonly class JwksController
{
    private SigningKeyLifecyclePolicy $lifecyclePolicy;

    public function __construct(
        private KeyMaterialProviderInterface $keyProvider,
        private JwksDocumentBuilder $builder,
        ?SigningKeyLifecyclePolicy $lifecyclePolicy = null,
    ) {
        $this->lifecyclePolicy = $lifecyclePolicy ?? new SigningKeyLifecyclePolicy();
    }

    public function __invoke(): JsonResponse
    {
        $keys = $this->keyProvider->allActive();
        $document = $this->builder->build($keys);

        $response = new JsonResponse($document, 200);
        $response->headers->set(
            'Cache-Control',
            'public, max-age=' . $this->lifecyclePolicy->jwksCacheLifetimeSeconds(),
        );

        return $response;
    }
}
