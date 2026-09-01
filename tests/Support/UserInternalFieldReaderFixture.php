<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\User\UserAuthorizationSnapshot;
use Waaseyaa\Access\User\UserCredentialSnapshot;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserMailSnapshot;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserTwoFactorSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\AuditedUserInternalFieldReader;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityInterface;

/** Test-only adapter for unit tests below the audit integration boundary. */
final class UserInternalFieldReaderFixture implements UserInternalFieldReaderInterface
{
    private AuditedUserInternalFieldReader $reader;

    public function __construct()
    {
        $registry = new InMemoryCapabilityRegistry();
        foreach ([
            ['user.credentials', CapabilityReason::CredentialVerification, ['status', 'pass', 'legacy_pass']],
            ['user.two-factor', CapabilityReason::CredentialVerification, ['mail', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step']],
            ['user.mail-delivery', CapabilityReason::MailDelivery, ['name', 'mail']],
            ['user.verification', CapabilityReason::CredentialVerification, ['mail', 'email_verified', 'status']],
            ['user.session-identity', CapabilityReason::SessionBootstrap, ['name', 'mail', 'roles', 'session_generation']],
            ['user.maintenance-authorization', CapabilityReason::MaintenanceCli, ['roles', 'permissions']],
        ] as [$issuer, $reason, $fields]) {
            $registry->register(new CapabilityDeclaration(
                issuer: $issuer,
                reason: $reason,
                entityTypes: ['user'],
                bundles: ['user'],
                fields: $fields,
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                justification: 'Exact test fixture for the audited User internal reader.',
            ));
        }
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            private int $sequence = 0;

            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('fixture-read-' . ++$this->sequence);
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $this->reader = new AuditedUserInternalFieldReader(new AuditedFieldRead($registry, $ledger), $registry);
    }

    public function credentials(EntityInterface $user): UserCredentialSnapshot
    {
        return $this->reader->credentials($user);
    }

    public function twoFactor(EntityInterface $user): UserTwoFactorSnapshot
    {
        return $this->reader->twoFactor($user);
    }

    public function mailDelivery(EntityInterface $user): UserMailSnapshot
    {
        return $this->reader->mailDelivery($user);
    }

    public function verification(EntityInterface $user): UserVerificationSnapshot
    {
        return $this->reader->verification($user);
    }

    public function sessionIdentity(EntityInterface $user): UserSessionSnapshot
    {
        return $this->reader->sessionIdentity($user);
    }

    public function maintenanceAuthorization(EntityInterface $user): UserAuthorizationSnapshot
    {
        return $this->reader->maintenanceAuthorization($user);
    }
}
