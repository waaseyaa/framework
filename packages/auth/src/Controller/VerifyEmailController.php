<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\EmailVerificationTransaction;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;

final class VerifyEmailController
{
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly EmailVerificationTransaction $verificationTransaction,
        ?AuthExtensionRegistry $extensions = null,
    ) {
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Parse JSON body, extract token
        $body = json_decode($request->getContent(), true) ?? [];
        $token = trim((string) ($body['token'] ?? ''));

        // 2. Return 422 if token is empty
        if ($token === '') {
            return new JsonResponse(['error' => 'token_required'], 422);
        }

        // 3. Validate token
        $tokenData = $this->tokenRepo->validateToken($token, 'email_verification');
        if ($tokenData === null) {
            return new JsonResponse(['error' => 'invalid_token'], 422);
        }

        // 4. Load user from token data (C-22 WP3: canonical repository).
        $repository = $this->entityTypeManager->getRepository('user');
        $entity = $repository->find((string) $tokenData['user_id']);

        if ($entity === null) {
            return new JsonResponse(['error' => 'user_not_found'], 422);
        }

        /** @var \Waaseyaa\User\User $user */
        $user = $entity;
        if (!$this->verificationTransaction->complete($repository, $user, $tokenData['id'])) {
            return new JsonResponse(['error' => 'invalid_token'], 422);
        }

        $userId = (string) $user->id();
        $this->extensions->dispatch('email_verified', $userId);

        // 7. Return 200
        return new JsonResponse([
            'ok' => true,
            'meta' => ['redirect' => $this->extensions->redirect('verification', $userId)->path],
        ]);
    }
}
