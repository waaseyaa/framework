<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\User\Http\AuthController;

final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return new JsonResponse([
                'jsonapi' => ['version' => '1.1'],
                'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'Session middleware did not set account.']],
            ], 500);
        }

        $authController = new AuthController();
        $result = $authController->me($account);

        $statusCode = $result['statusCode'];
        unset($result['statusCode']);

        return new JsonResponse(
            array_merge(['jsonapi' => ['version' => '1.1']], $result),
            $statusCode,
        );
    }
}
