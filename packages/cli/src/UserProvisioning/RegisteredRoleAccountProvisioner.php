<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\UserProvisioning;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Exception\EntityMutationCommittedSideEffectsFailedException;
use Waaseyaa\User\RegisteredRoleAssignment;
use Waaseyaa\User\RegisteredRoleAssignmentService;

/** Creates or verifies one account with one explicitly selected registered role. @api */
final readonly class RegisteredRoleAccountProvisioner
{
    public function __construct(
        private RegisteredRoleAssignmentService $assignments,
        private UserIdentityLookupInterface $identities,
        private UserInternalFieldReaderInterface $internalFields,
        private ?\Closure $clock = null,
    ) {}

    public function provision(
        EntityRepositoryInterface $repository,
        string $username,
        string $mail,
        string $roleId,
        #[\SensitiveParameter]
        string $password,
    ): RegisteredRoleProvisioningResult {
        $invalid = $this->validate($username, $mail, $roleId, $password);
        if ($invalid !== null) {
            return new RegisteredRoleProvisioningResult('refused', $invalid, self::publicRole($roleId));
        }
        if ($roleId === 'administrator') {
            return new RegisteredRoleProvisioningResult('refused', 'reserved_role', $roleId);
        }

        try {
            $assignment = $this->assignments->change([], $roleId);
        } catch (\InvalidArgumentException) {
            return new RegisteredRoleProvisioningResult('refused', 'unknown_role', $roleId);
        }

        try {
            $existing = $this->resolveSettledExisting($repository, $username, $mail, $password, $assignment, $roleId);
        } catch (\Throwable) {
            return new RegisteredRoleProvisioningResult('refused', 'identity_lookup_failed', $roleId);
        }
        if ($existing !== null) {
            return $existing;
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        } catch (\Throwable) {
            return new RegisteredRoleProvisioningResult('refused', 'credential_hash_failed', $roleId);
        }
        $user = null;
        try {
            $user = $repository->create([
                'name' => $username,
                'mail' => $mail,
                'pass' => $passwordHash,
                'legacy_pass' => null,
                'roles' => $assignment->roles,
                'permissions' => $assignment->permissions,
                'status' => true,
                'email_verified' => false,
                'created' => ($this->clock ?? static fn(): int => time())(),
            ]);
            $repository->save($user);
        } catch (EntityMutationCommittedSideEffectsFailedException) {
            return new RegisteredRoleProvisioningResult(
                'uncertain',
                'account_committed_completion_failed',
                $roleId,
                self::accountId($user),
            );
        } catch (UniqueConstraintViolationException) {
            try {
                return $this->resolveSettledExisting($repository, $username, $mail, $password, $assignment, $roleId)
                    ?? new RegisteredRoleProvisioningResult('refused', 'identity_conflict', $roleId);
            } catch (\Throwable) {
                return new RegisteredRoleProvisioningResult('uncertain', 'conflict_resolution_failed', $roleId);
            }
        } catch (\Throwable) {
            // A competing writer can make the local transaction fail before
            // its physical unique violation is observable (notably SQLite's
            // snapshot-upgrade conflict). Never repeat the write. Reconcile
            // once through the same authorized exact-match path instead.
            try {
                $resolved = $this->resolveSettledExisting($repository, $username, $mail, $password, $assignment, $roleId);
                if ($resolved !== null) {
                    return $resolved;
                }
            } catch (\Throwable) {
                return $user instanceof EntityInterface
                    ? new RegisteredRoleProvisioningResult('uncertain', 'storage_outcome_unresolved', $roleId)
                    : new RegisteredRoleProvisioningResult('refused', 'storage_failure', $roleId);
            }

            return $user instanceof EntityInterface
                ? new RegisteredRoleProvisioningResult('uncertain', 'storage_outcome_unresolved', $roleId)
                : new RegisteredRoleProvisioningResult('refused', 'storage_failure', $roleId);
        }

        $accountId = self::accountId($user);
        if ($accountId === null) {
            return new RegisteredRoleProvisioningResult('uncertain', 'account_identity_unavailable', $roleId);
        }

        return new RegisteredRoleProvisioningResult('created', 'account_created', $roleId, $accountId);
    }

    private function resolveSettledExisting(
        EntityRepositoryInterface $repository,
        string $username,
        string $mail,
        #[\SensitiveParameter]
        string $password,
        RegisteredRoleAssignment $assignment,
        string $roleId,
    ): ?RegisteredRoleProvisioningResult {
        $first = $this->resolveExisting($repository, $username, $mail, $password, $assignment, $roleId);
        if ($first?->code !== 'identity_conflict') {
            return $first;
        }

        // Login and mail are separate audited reads. If another transaction
        // commits between them, one can observe absence and the other presence.
        // Re-read once before declaring a durable conflict; never retry a write.
        return $this->resolveExisting($repository, $username, $mail, $password, $assignment, $roleId) ?? $first;
    }

    private function validate(string $username, string $mail, string $roleId, #[\SensitiveParameter] string $password): ?string
    {
        if (strlen($username) > 64 || preg_match('/\A[a-z](?:[a-z0-9._-]{0,62}[a-z0-9])?\z/D', $username) !== 1) {
            return 'invalid_username';
        }
        if (strlen($mail) > 254 || strtolower($mail) !== $mail || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_mail';
        }
        if (strlen($roleId) > 64 || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $roleId) !== 1) {
            return 'invalid_role';
        }
        $passwordLength = strlen($password);
        if ($passwordLength < 12 || $passwordLength > 1024 || str_contains($password, "\0") || str_contains($password, "\r") || str_contains($password, "\n")) {
            return 'invalid_password';
        }

        return null;
    }

    private function resolveExisting(
        EntityRepositoryInterface $repository,
        string $username,
        string $mail,
        #[\SensitiveParameter]
        string $password,
        RegisteredRoleAssignment $assignment,
        string $roleId,
    ): ?RegisteredRoleProvisioningResult {
        $byName = $this->identities->findActiveByLogin($repository, $username);
        $byMail = $this->identities->findActiveByMail($repository, $mail);
        $loginExists = $this->identities->loginExists($repository, $username);
        $mailExists = $this->identities->mailExists($repository, $mail);
        if ($byName === null && $byMail === null) {
            return $loginExists || $mailExists
                ? new RegisteredRoleProvisioningResult('refused', 'identity_conflict', $roleId)
                : null;
        }
        if ($byName === null || $byMail === null || self::accountId($byName) !== self::accountId($byMail)) {
            return new RegisteredRoleProvisioningResult('refused', 'identity_conflict', $roleId);
        }

        $identity = $this->internalFields->mailDelivery($byName);
        $credentials = $this->internalFields->credentials($byName);
        $authorization = $this->internalFields->maintenanceAuthorization($byName);
        $matches = $identity->name === $username
            && $identity->mail === $mail
            && $credentials->active
            && $credentials->legacyPasswordHash === null
            && $credentials->passwordHash !== ''
            && password_verify($password, $credentials->passwordHash)
            && $authorization->roles === $assignment->roles
            && $authorization->permissions === $assignment->permissions;

        return $matches
            ? new RegisteredRoleProvisioningResult('existing', 'account_already_matches', $roleId, self::accountId($byName))
            : new RegisteredRoleProvisioningResult('refused', 'identity_conflict', $roleId);
    }

    private static function accountId(EntityInterface $user): ?string
    {
        $id = $user->id();

        return is_int($id) || (is_string($id) && $id !== '') ? (string) $id : null;
    }

    private static function publicRole(string $roleId): string
    {
        return strlen($roleId) <= 64 && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/D', $roleId) === 1
            ? $roleId
            : '';
    }
}
