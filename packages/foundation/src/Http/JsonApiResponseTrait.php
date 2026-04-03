<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

trait JsonApiResponseTrait
{
    /**
     * Build a JSON:API response with correct content type and encoding.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    private function jsonApiResponse(int $status, array $data, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($data, $status, $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $response->headers->set('Content-Type', 'application/vnd.api+json');

        return $response;
    }
}
