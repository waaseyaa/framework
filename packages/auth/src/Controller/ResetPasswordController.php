<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Session\AuthenticatedSession;

final class ResetPasswordController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Parse JSON body
        $body = json_decode($request->getContent(), true) ?? [];
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirmation = (string) ($body['password_confirmation'] ?? '');

        // 2. Validate password
        if (strlen($password) < 8) {
            return new JsonResponse(['error' => 'password_too_short'], 422);
        }

        if ($password !== $passwordConfirmation) {
            return new JsonResponse(['error' => 'passwords_do_not_match'], 422);
        }

        // 3. Validate token
        $tokenData = $this->tokenRepo->validateToken($token, 'password_reset');
        if ($tokenData === null) {
            return new JsonResponse(['error' => 'invalid_or_expired_token'], 422);
        }

        // 4. Load user (C-22 WP3: canonical repository).
        $repository = $this->entityTypeManager->getRepository('user');
        $entity = $repository->find((string) $tokenData['user_id']);
        if ($entity === null) {
            return new JsonResponse(['error' => 'user_not_found'], 422);
        }

        /** @var \Waaseyaa\User\User $user */
        $user = $entity;

        // 5. Update password
        $generation = $this->internalFields->sessionIdentity($user)->generation;
        $user->setRawPassword($password);
        $user->setSessionGeneration($generation + 1);
        $repository->save($user);

        // 6. Consume token and revoke all password_reset tokens for user
        $this->tokenRepo->consumeToken($tokenData['id']);
        $this->tokenRepo->revokeTokensForUser($tokenData['user_id'], 'password_reset');

        // 7. Destroy session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        AuthenticatedSession::clearIdentity();

        // 8. Return 200
        return new JsonResponse([
            'ok' => true,
            'message' => 'Password has been reset. Please sign in.',
        ]);
    }
}
