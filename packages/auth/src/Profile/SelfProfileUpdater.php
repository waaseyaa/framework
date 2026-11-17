<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Profile;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Auth\AuthManager;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Exception\TransactionCompletionException;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\User\AuthMailer;
use Waaseyaa\User\User;

/** Canonical authenticated self-profile mutation and email-change lifecycle. @api */
final readonly class SelfProfileUpdater
{
    private const array RESERVED = ['uid', 'uuid', 'name', 'pass', 'legacy_pass', 'roles', 'permissions',
        'status', 'email_verified', 'session_generation', 'created', 'identity_name_key', 'identity_mail_key',
        'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step', 'tenant_id', 'community_id'];

    /** @param array<mixed> $profileFields Application configuration validated here, never request data. */
    public function __construct(
        private EntityTypeManagerInterface $manager,
        private DatabaseInterface $database,
        private AuthTokenRepositoryInterface $tokens,
        private AccountFieldReadScopeInterface $scope,
        private EntityAccessHandler $access,
        private UserInternalFieldReaderInterface $fields,
        private UserIdentityLookupInterface $identities,
        private AuthManager $auth,
        private AtomicRateLimiterInterface $limiter,
        private AuthConfig $config,
        private AuthMailer $mailer,
        private AuthExtensionRegistry $extensions,
        private array $profileFields = [],
    ) {
        if (!array_is_list($profileFields)) {
            throw new \InvalidArgumentException('Self-profile fields must be a list.');
        }
        foreach ($profileFields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]*$/D', $field)
                || $field === 'mail' || in_array($field, self::RESERVED, true)) {
                throw new \InvalidArgumentException('Self-profile fields must be noncredential application fields.');
            }
        }
    }

    /** @param array<string, mixed> $changes */
    public function save(
        AuthorizationPrincipalInterface $actor,
        EntityMutationToken $expected,
        string $password,
        array $changes,
    ): SelfProfileUpdateResult {
        if (!$actor->isAuthenticated() || $this->scope->current() !== $actor) {
            throw new SelfProfileUpdateException('account_required', 403);
        }
        if (!$this->database instanceof DBALDatabase || $this->database->getConnection()->getTransactionNestingLevel() !== 0) {
            throw new \LogicException('Self-profile save must own the outermost canonical transaction.');
        }
        if ($changes === [] || array_diff(array_keys($changes), ['mail', ...$this->profileFields]) !== []) {
            throw new SelfProfileUpdateException('invalid_fields', 422);
        }
        foreach ($changes as $field => $value) {
            if (!is_string($value) || mb_strlen($value) > 254) {
                throw new SelfProfileUpdateException('invalid_fields', 422);
            }
            $changes[$field] = trim($value);
        }
        if (isset($changes['mail'])) {
            $changes['mail'] = strtolower($changes['mail']);
            if (filter_var($changes['mail'], FILTER_VALIDATE_EMAIL) === false) {
                throw new SelfProfileUpdateException('invalid_email', 422);
            }
        }
        $limitKey = 'self_profile:'.hash('sha256', json_encode([$actor->tenantId(), $actor->communityId(), (string) $actor->id()], JSON_THROW_ON_ERROR));
        if (!$this->limiter->consume($limitKey, 5, 60)) {
            throw new SelfProfileUpdateException('too_many_attempts', 429);
        }
        $repository = $this->manager->getRepository('user');
        if (!$repository instanceof EntityRepository || !$repository->sharesTransactionWith($this->database)
            || !$this->tokens instanceof AuthTokenRepository || !$this->tokens->sharesTransactionWith($this->database)) {
            throw new \LogicException('Self-profile save requires canonical stores sharing one transaction.');
        }
        $transaction = $this->database->transaction('auth-self-profile');
        $verificationToken = null;
        try {
            $user = $repository->find((string) $actor->id());
            if (!$user instanceof User || !$this->access->check($user, 'update', $actor)->isAllowed()) {
                throw new SelfProfileUpdateException('account_required', 403);
            }
            $actual = $user->mutationToken();
            if ($actual === null || !hash_equals($actual->toOpaqueString(), $expected->toOpaqueString())) {
                throw new SelfProfileUpdateException('profile_changed', 409);
            }
            foreach (array_keys($changes) as $field) {
                if ($this->access->checkFieldAccess($user, $field, 'edit', $actor)->isForbidden()) {
                    throw new SelfProfileUpdateException('invalid_fields', 403);
                }
            }
            if (!$this->auth->authenticate($user, $password)) {
                throw new SelfProfileUpdateException('incorrect_password', 422);
            }
            $identity = $this->fields->verification($user);
            $emailChanged = isset($changes['mail']) && $changes['mail'] !== strtolower(trim($identity->mail));
            if (!$emailChanged) {
                // A name-only save must not rewrite historical case or claim a legacy mail key.
                unset($changes['mail']);
            }
            // Older imported rows may not yet claim the derived unique-mail key.
            if ($emailChanged && $this->identities->mailExists($repository, $changes['mail'])) {
                throw new SelfProfileUpdateException('email_unavailable', 409);
            }
            if ($emailChanged && $this->config->requireVerifiedEmail && !$this->mailer->isConfigured()) {
                throw new SelfProfileUpdateException('verification_unavailable', 503);
            }
            foreach ($changes as $field => $value) {
                $user->set($field, $value);
            }
            if ($emailChanged) {
                $user->setEmailVerified(false);
                $user->setSessionGeneration($this->fields->sessionIdentity($user)->generation + 1);
            }
            $repository->save($user);
            if ($emailChanged) {
                $this->tokens->revokeTokensForUser($user->id());
                if ($this->config->requireVerifiedEmail) {
                    $verificationToken = $this->tokens->createToken($user->id(), 'email_verification', $this->config->tokenTtl('email_verification'));
                }
            }
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
        $completionEffectsFailed = false;
        try {
            $transaction->commit();
        } catch (TransactionCompletionException) {
            // This direct outer commit completed durably before draining effects.
            $completionEffectsFailed = true;
        } catch (\Throwable $error) {
            $transaction->rollBack();
            throw $error;
        }
        $sent = false;
        if ($verificationToken !== null) {
            try {
                $this->mailer->sendEmailVerification($user, $verificationToken, $this->extensions->mail('email_verification', (string) $user->id()));
                $sent = true;
            } catch (\Throwable) {
                // The profile is committed. A caller must offer resend rather than replaying the save.
            }
        }
        return new SelfProfileUpdateResult($emailChanged, $verificationToken !== null, $sent, $completionEffectsFailed);
    }
}
