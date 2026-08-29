<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Bootstrap;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserTwoFactorSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;

/** Issues, uses, and revokes exact framework-owned User read authority. @api */
final readonly class AuditedUserInternalFieldReader implements UserInternalFieldReaderInterface
{
    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration = 'wp4-user-internal-v1',
        private string $policyGeneration = 'wp4-user-internal-v1',
    ) {}

    public function credentials(EntityInterface $user): UserCredentialSnapshot
    {
        // `legacy_pass` is read under the SAME credential-verification authority
        // as `pass` — it is a password equivalent until the first successful
        // login upgrades it away (#2544), so it must not be reachable through
        // any weaker reason.
        $values = $this->read(
            'user.credentials',
            CapabilityReason::CredentialVerification,
            $user,
            ['status', 'pass', 'legacy_pass'],
        );
        $legacy = $values['legacy_pass'] ?? null;

        return new UserCredentialSnapshot(
            (bool) ($values['status'] ?? false),
            (string) ($values['pass'] ?? ''),
            is_string($legacy) && $legacy !== '' ? $legacy : null,
        );
    }

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot
    {
        $values = $this->read('user.two-factor', CapabilityReason::CredentialVerification, $user, [
            'mail', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step',
        ]);
        $hashes = is_array($values['two_factor_recovery_codes_hash'] ?? null)
            ? array_values(array_filter($values['two_factor_recovery_codes_hash'], 'is_string'))
            : [];

        return new UserTwoFactorSnapshot(
            (string) ($values['mail'] ?? ''),
            is_string($values['two_factor_secret'] ?? null) ? $values['two_factor_secret'] : null,
            $hashes,
            is_int($values['two_factor_last_used_step'] ?? null) ? $values['two_factor_last_used_step'] : null,
        );
    }

    public function mailDelivery(EntityInterface $user): UserMailSnapshot
    {
        $values = $this->read('user.mail-delivery', CapabilityReason::MailDelivery, $user, ['name', 'mail']);

        return new UserMailSnapshot((string) ($values['name'] ?? ''), (string) ($values['mail'] ?? ''));
    }

    public function verification(EntityInterface $user): UserVerificationSnapshot
    {
        $values = $this->read('user.verification', CapabilityReason::CredentialVerification, $user, ['mail', 'email_verified']);

        return new UserVerificationSnapshot((string) ($values['mail'] ?? ''), (bool) ($values['email_verified'] ?? false));
    }

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot
    {
        $values = $this->read('user.session-identity', CapabilityReason::SessionBootstrap, $user, ['name', 'mail', 'roles']);

        return new UserSessionSnapshot(
            (string) ($values['name'] ?? ''),
            (string) ($values['mail'] ?? ''),
            $this->strings($values['roles'] ?? []),
        );
    }

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot
    {
        $values = $this->read('user.maintenance-authorization', CapabilityReason::MaintenanceCli, $user, ['roles', 'permissions']);

        return new UserAuthorizationSnapshot($this->strings($values['roles'] ?? []), $this->strings($values['permissions'] ?? []));
    }

    /** @param non-empty-list<string> $fields @return array<string, mixed> */
    private function read(string $issuer, CapabilityReason $reason, EntityInterface $user, array $fields): array
    {
        $boundary = $this->capabilities->openBoundary(bin2hex(random_bytes(16)));
        $capability = $this->capabilities->issueValueRead($issuer, new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
        try {
            return $this->reader->readMany($capability, $boundary, $user, $fields, $reason);
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }
}
