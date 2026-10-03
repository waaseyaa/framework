<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

/** Stateless opaque diagnostic; it neither reads account state nor queries data. */
final readonly class NotExposedController
{
    /** @return array{statusCode: int, body: array<string, mixed>} */
    public function __invoke(): array
    {
        return ['statusCode' => 404, 'body' => [
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '404', 'title' => 'Not Found',
                'detail' => 'No route matches the requested path.',
            ]],
        ]];
    }
}
