<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class LogoutController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
        }

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'meta' => ['message' => 'Logged out.'],
        ]);
    }
}
