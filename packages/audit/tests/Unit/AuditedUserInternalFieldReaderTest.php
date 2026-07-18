<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\AuditedUserInternalFieldReader;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityInterface;

final class AuditedUserInternalFieldReaderTest extends TestCase
{
    public function test_reason_specific_operations_issue_exact_reads_and_revoke_authority(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        foreach ([
            ['user.credentials', CapabilityReason::CredentialVerification, ['status', 'pass']],
            ['user.two-factor', CapabilityReason::CredentialVerification, ['mail', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step']],
            ['user.mail-delivery', CapabilityReason::MailDelivery, ['name', 'mail']],
            ['user.verification', CapabilityReason::CredentialVerification, ['mail', 'email_verified']],
            ['user.session-identity', CapabilityReason::SessionBootstrap, ['name', 'mail', 'roles']],
            ['user.maintenance-authorization', CapabilityReason::MaintenanceCli, ['roles', 'permissions']],
        ] as [$issuer, $reason, $fields]) {
            $registry->register(new CapabilityDeclaration(
                issuer: $issuer,
                reason: $reason,
                entityTypes: ['user'],
                bundles: ['user'],
                fields: $fields,
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                justification: 'Exact framework User internal read.',
            ));
        }
        $descriptors = [];
        $ledger = new class($descriptors) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$descriptors) {}
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->descriptors[] = $descriptor;
                return new PrivilegedReadReceipt('read-'.count($this->descriptors));
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $user = new class implements EntityInterface {
            private array $values = [
                'status' => 1, 'pass' => 'hash', 'mail' => 'member@example.test', 'name' => 'Member',
                'email_verified' => 1, 'roles' => ['editor'], 'permissions' => ['edit'],
                'two_factor_secret' => 'secret', 'two_factor_recovery_codes_hash' => ['recovery'],
                'two_factor_last_used_step' => 123,
            ];
            public function id(): int|string|null { return 7; }
            public function uuid(): string { return 'user-7'; }
            public function label(): string { return 'Member'; }
            public function getEntityTypeId(): string { return 'user'; }
            public function bundle(): string { return 'user'; }
            public function isNew(): bool { return false; }
            public function get(string $name): mixed { return $this->values[$name] ?? null; }
            public function set(string $name, mixed $value): static { $this->values[$name] = $value; return $this; }
            public function toArray(): array { return $this->values; }
            public function language(): string { return 'en'; }
        };
        $reader = new AuditedUserInternalFieldReader(new AuditedFieldRead($registry, $ledger), $registry);

        self::assertSame('hash', $reader->credentials($user)->passwordHash);
        self::assertSame('secret', $reader->twoFactor($user)->secret);
        self::assertSame('member@example.test', $reader->mailDelivery($user)->mail);
        self::assertTrue($reader->verification($user)->emailVerified);
        self::assertSame(['editor'], $reader->sessionIdentity($user)->roles);
        self::assertSame(['editor'], $reader->maintenanceAuthorization($user)->roles);
        self::assertSame([
            ['status', 'pass'],
            ['mail', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step'],
            ['name', 'mail'],
            ['mail', 'email_verified'],
            ['name', 'mail', 'roles'],
            ['roles', 'permissions'],
        ], array_map(static fn(PrivilegedReadDescriptor $descriptor): array => $descriptor->fields, $descriptors));
    }
}
