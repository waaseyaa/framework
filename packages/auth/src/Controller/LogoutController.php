<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;

final class LogoutController
{
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(?AuthExtensionRegistry $extensions = null)
    {
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

    public function __invoke(Request $request): JsonResponse
    {
        $userId = isset($_SESSION['waaseyaa_uid']) ? (string) $_SESSION['waaseyaa_uid'] : null;
        if (session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_regenerate_id(true);
            session_destroy();
        }

        if ($userId !== null && $userId !== '') {
            $this->extensions->dispatch('logout_succeeded', $userId);
        }

        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'meta' => [
                'message' => 'Logged out.',
                'redirect' => $this->extensions->redirect('logout', $userId)->path,
            ],
        ]);
    }
}
