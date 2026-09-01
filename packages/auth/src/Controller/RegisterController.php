<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Extension\RegisteredUserReference;
use Waaseyaa\Auth\Extension\RegistrationContext;
use Waaseyaa\Auth\Extension\RegistrationProfileValidationException;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\Authentication\AuthenticationEligibilityInterface;
use Waaseyaa\User\Authentication\AuthenticationStage;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

final class RegisterController
{
    private readonly LoggerInterface $logger;
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(
        private readonly AuthConfig $config,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly AuthMailer $authMailer,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly UserIdentityLookupInterface $identityLookup,
        private readonly UserInternalFieldReaderInterface $internalFields,
        private readonly AuthenticationEligibilityInterface $eligibility,
        ?LoggerInterface $logger = null,
        ?AuthExtensionRegistry $extensions = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Check registration mode
        if ($this->config->registration === 'admin') {
            return new JsonResponse(['error' => 'registration_disabled'], 403);
        }

        // 2. Rate limiting: 5 attempts per IP per 15 minutes
        $ip = $request->getClientIp() ?? 'unknown';
        $rateLimitKey = 'register:' . $ip;
        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, 5)) {
            return new JsonResponse(['error' => 'too_many_attempts'], 429);
        }
        $this->rateLimiter->hit($rateLimitKey, 900);

        // 3. Parse JSON body
        $body = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $inviteToken = (string) ($body['invite_token'] ?? '');

        // 4. Validate fields
        $errors = [];

        if (strlen($name) < 2 || strlen($name) > 255) {
            $errors['name'] = 'Name must be between 2 and 255 characters.';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        // 5. Invite mode: validate invite token
        $inviteTokenData = null;

        if ($this->config->registration === 'invite') {
            if ($inviteToken === '') {
                $errors['invite_token'] = 'An invite token is required.';
            } else {
                $inviteTokenData = $this->tokenRepo->validateToken($inviteToken, 'invite');
                if ($inviteTokenData === null) {
                    $errors['invite_token'] = 'Invalid or expired invite token.';
                }
            }
        }

        if ($errors !== []) {
            return new JsonResponse(['errors' => $errors], 422);
        }

        $decision = $this->extensions->registration(new RegistrationContext($name, $email, $this->config->registration));
        if (!$decision->allowed) {
            return new JsonResponse(['error' => 'registration_disabled'], 403);
        }

        try {
            $profile = $this->extensions->validateProfile($body['profile'] ?? null);
        } catch (RegistrationProfileValidationException $error) {
            return new JsonResponse(['errors' => $error->errors], 422);
        }

        // 6. Check email uniqueness (anti-enumeration: generic 422). C-22 WP3:
        // loadByKey() has no repository equivalent, so this is a bounded query + find().
        $repository = $this->entityTypeManager->getRepository('user');
        if ($this->identityLookup->mailExists($repository, $email)) {
            return new JsonResponse(['errors' => ['email' => 'Registration failed. Please try again.']], 422);
        }

        // 7. Create User entity
        $emailVerified = $this->config->registration === 'invite' ? 1 : 0;

        $user = new User([
            'name' => $name,
            'mail' => $email,
            'status' => $decision->requiresApproval ? 0 : 1,
            'email_verified' => $emailVerified,
        ]);

        // 8. Set password, mark new, save
        $user->setRawPassword($password);
        $user->enforceIsNew();
        $repository->save($user);

        $reference = new RegisteredUserReference((string) $user->id(), $name, $decision->requiresApproval);
        try {
            $this->extensions->applyInitialRoles($user, $reference);
            if (isset($this->extensions->owners()['initial_roles'])) {
                $repository->save($user);
            }
            $this->extensions->storeProfile($reference, $profile);
        } catch (\Throwable $error) {
            try {
                $repository->delete($user);
            } catch (\Throwable $rollbackFailure) {
                $this->logger->error(sprintf(
                    'Registration extension failed and user rollback also failed: %s; rollback: %s',
                    $error->getMessage(),
                    $rollbackFailure->getMessage(),
                ));
            }
            throw $error;
        }

        // 9. Consume invite token if applicable
        if ($this->config->registration === 'invite' && $inviteTokenData !== null) {
            $this->tokenRepo->consumeToken($inviteTokenData['id']);
        }

        // 10. Open mode: send verification email (or dev-log)
        if ($this->config->registration === 'open') {
            $verifyToken = $this->tokenRepo->createToken(
                $user->id(),
                'email_verification',
                $this->config->tokenTtl('email_verification'),
            );

            if ($this->authMailer->isConfigured()) {
                $this->authMailer->sendEmailVerification(
                    $user,
                    $verifyToken,
                    $this->extensions->mail('email_verification', (string) $user->id()),
                );
            } elseif ($this->config->mailMissingPolicy === \Waaseyaa\Auth\Config\MailMissingPolicy::DevLog) {
                $this->logger->info('Email verification URL for ' . $email . ': /verify-email?token=' . $verifyToken);
            }
        }

        // 11. Send welcome email (best-effort)
        try {
            $this->authMailer->sendWelcome(
                $user,
                $this->extensions->mail('welcome', (string) $user->id()),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Welcome email failed: ' . $e->getMessage());
        }

        // 12. Auto-login: regenerate session, set waaseyaa_uid
        if (!$decision->requiresApproval && $this->eligibility->allows($user, AuthenticationStage::Registration)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            AuthenticatedSession::issue($user, $this->internalFields->sessionIdentity($user)->generation);
        }

        $this->extensions->dispatch('registered', (string) $user->id(), [
            'approval_required' => $decision->requiresApproval,
        ]);

        // 13. Return 201 with user data
        $identity = $this->internalFields->verification($user);

        return new JsonResponse([
            'data' => [
                'id' => $user->id(),
                // The public registration route has no ambient account read
                // context. Return the validated request value that was just
                // persisted instead of re-reading the Protected entity field.
                'name' => $name,
                'email' => $identity->mail,
                'email_verified' => $identity->emailVerified,
            ],
            'meta' => [
                'approval_required' => $decision->requiresApproval,
                'verification_required' => $this->config->requireVerifiedEmail && !$identity->emailVerified,
                'redirect' => $this->extensions->redirect('registration', (string) $user->id())->path,
            ],
        ], 201);
    }
}
