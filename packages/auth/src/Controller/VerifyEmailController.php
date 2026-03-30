<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;

final class VerifyEmailController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Parse JSON body, extract token
        $body = json_decode((string) $request->getContent(), true) ?? [];
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

        // 4. Load user from token data
        $storage = $this->entityTypeManager->getStorage('user');
        $entity = $storage->load($tokenData['user_id']);

        if ($entity === null) {
            return new JsonResponse(['error' => 'user_not_found'], 422);
        }

        // 5. Mark email verified and save
        /** @var \Waaseyaa\User\User $user */
        $user = $entity;
        $user->setEmailVerified(true);
        $storage->save($user);

        // 6. Consume token and revoke all email_verification tokens for user
        $this->tokenRepo->consumeToken($tokenData['id']);
        $this->tokenRepo->revokeTokensForUser($tokenData['user_id'], 'email_verification');

        // 7. Return 200
        return new JsonResponse(['ok' => true]);
    }
}
