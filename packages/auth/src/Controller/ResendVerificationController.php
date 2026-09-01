<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Config\MailMissingPolicy;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;

final class ResendVerificationController
{
    private readonly LoggerInterface $logger;
    private readonly AuthExtensionRegistry $extensions;

    public function __construct(
        private readonly AuthConfig $config,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuthTokenRepositoryInterface $tokenRepo,
        private readonly AuthMailer $authMailer,
        private readonly AtomicRateLimiterInterface $rateLimiter,
        private readonly UserIdentityLookupInterface $identityLookup,
        private readonly UserInternalFieldReaderInterface $internalFields,
        ?LoggerInterface $logger = null,
        ?AuthExtensionRegistry $extensions = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->extensions = $extensions ?? AuthExtensionRegistry::defaults();
    }

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $submittedEmail = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        if ($submittedEmail === '' || filter_var($submittedEmail, FILTER_VALIDATE_EMAIL) === false) {
            return new JsonResponse(['error' => 'email_required'], 422);
        }
        $email = strtolower($submittedEmail);

        $mailConfigured = $this->authMailer->isConfigured();
        if (!$mailConfigured && $this->config->mailMissingPolicy === MailMissingPolicy::Fail) {
            return new JsonResponse(['error' => 'mail_not_configured'], 503);
        }

        // Database-backed rate limiters bound keys to 255 bytes. Hashing also
        // keeps attacker-controlled addresses out of the limiter ledger.
        $emailKey = 'resend_verification:email:' . hash('sha256', $email);
        $ipKey = 'resend_verification:ip:' . ($request->getClientIp() ?? 'unknown');
        $emailAllowed = $this->rateLimiter->consume($emailKey, 3, 3600);
        $ipAllowed = $this->rateLimiter->consume($ipKey, 10, 3600);
        if (!$emailAllowed || !$ipAllowed) {
            return new JsonResponse(
                ['error' => 'too_many_attempts'],
                429,
                ['Retry-After' => '3600'],
            );
        }
        $repository = $this->entityTypeManager->getRepository('user');
        $candidate = $this->identityLookup->findActiveByMail($repository, $submittedEmail);
        $user = $candidate instanceof User ? $candidate : null;
        if ($user !== null) {
            $verification = $this->internalFields->verification($user);
            $sameAddress = strtolower(trim($verification->mail)) === $email;
            if ($sameAddress && !$verification->emailVerified) {
                try {
                    $verifyToken = $this->tokenRepo->createToken(
                        $user->id(),
                        'email_verification',
                        $this->config->tokenTtl('email_verification'),
                    );
                    if ($mailConfigured) {
                        $this->authMailer->sendEmailVerification(
                            $user,
                            $verifyToken,
                            $this->extensions->mail('email_verification', (string) $user->id()),
                        );
                    } elseif ($this->config->mailMissingPolicy === MailMissingPolicy::DevLog) {
                        $this->logger->info('Email verification URL for local development: /verify-email?token=' . $verifyToken);
                    }
                } catch (\Throwable) {
                    try {
                        $this->tokenRepo->revokeTokensForUser($user->id(), 'email_verification');
                    } catch (\Throwable) {
                        // Public anti-enumeration also covers cleanup failure.
                    }
                    $this->logger->error('Verification delivery failed after a public resend request.');
                }
            }
        }

        return new JsonResponse([
            'ok' => true,
            'message' => 'If an unverified account exists for that email, a verification link has been sent.',
        ]);
    }
}
