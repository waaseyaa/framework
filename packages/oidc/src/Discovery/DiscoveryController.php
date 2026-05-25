<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Discovery;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /.well-known/openid-configuration — OIDC Discovery 1.0.
 *
 * @api
 */
final readonly class DiscoveryController
{
    public function __construct(
        private string $issuer,
        private DiscoveryDocumentBuilder $builder,
    ) {}

    public function __invoke(): JsonResponse
    {
        $document = $this->builder->build($this->issuer);
        $response = new JsonResponse($document, 200);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }
}
