<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\User\Session\AuthenticatedSession;

final class ResetPasswordController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly \Waaseyaa\Auth\Password\LegacyPasswordChange $passwordChange,
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

        $error = $this->passwordChange->complete($this->entityTypeManager->getRepository('user'), $tokenData, $password);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], 422);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        AuthenticatedSession::clearIdentity();
        unset($_SESSION['waaseyaa_pending_2fa_uid']);
        return new JsonResponse(['ok' => true, 'message' => 'Password has been reset. Please sign in.']);
    }
}
