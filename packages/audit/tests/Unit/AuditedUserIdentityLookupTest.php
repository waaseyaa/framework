<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Capability\QueryFieldOperation;
use Waaseyaa\Audit\AuditedQueryFieldRead;
use Waaseyaa\Audit\Bootstrap\AuditedUserIdentityLookup;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

final class AuditedUserIdentityLookupTest extends TestCase
{
    public function test_login_lookup_reserves_name_and_mail_queries_before_execution(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'user.identity-lookup',
            reason: CapabilityReason::CredentialVerification,
            entityTypes: ['user'],
            bundles: ['user'],
            queryFields: ['name', 'mail', 'status'],
            queryOperations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            justification: 'Resolve an active login identity.',
        ));
        $events = [];
        $ledger = new class($events) implements StrictPrivilegedReadLedgerInterface {
            public function __construct(private array &$events) {}
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                $this->events[] = 'reserve:'.implode(',', $descriptor->fields);
                return new PrivilegedReadReceipt('query-'.count($this->events));
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void
            {
                $this->events[] = 'finalize:'.$outcome->value;
            }
        };
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->expects(self::once())->method('execute')->willReturn([7]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $user = $this->createStub(EntityInterface::class);
        $repository->expects(self::once())->method('find')->with('7')->willReturn($user);

        $lookup = new AuditedUserIdentityLookup(new AuditedQueryFieldRead($registry, $ledger), $registry);

        self::assertSame($user, $lookup->findActiveByLogin($repository, 'member'));
        self::assertSame(['reserve:name,status', 'finalize:succeeded'], $events);
    }
}
