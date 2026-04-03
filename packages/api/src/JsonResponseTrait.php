<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\HttpResponse;

trait JsonResponseTrait
{
    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        try {
            return (array) json_decode((string) $content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $status,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
