<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
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
    private function identityRegistry(): InMemoryCapabilityRegistry
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

        return $registry;
    }

    private function silentLedger(): StrictPrivilegedReadLedgerInterface
    {
        return new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('query-' . bin2hex(random_bytes(4)));
            }

            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
    }

    /**
     * @param list<array{0: string, 1: mixed, 2: string}> $conditions
     * @param list<array{0: int, 1: int}>                 $ranges
     *
     * @return EntityQueryInterface&MockObject
     */
    private function recordingQuery(array &$conditions, array &$ranges): EntityQueryInterface
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnCallback(
            static function (string $field, mixed $value, string $operator = '=') use (&$conditions, $query): EntityQueryInterface {
                $conditions[] = [$field, $value, $operator];

                return $query;
            },
        );
        $query->method('range')->willReturnCallback(
            static function (int $offset, int $limit) use (&$ranges, $query): EntityQueryInterface {
                $ranges[] = [$offset, $limit];

                return $query;
            },
        );

        return $query;
    }

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

    public function test_mail_uniqueness_uses_canonical_case_insensitive_equality(): void
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'user.identity-lookup',
            reason: CapabilityReason::CredentialVerification,
            entityTypes: ['user'],
            bundles: ['user'],
            queryFields: ['mail'],
            queryOperations: [QueryFieldOperation::Predicate, QueryFieldOperation::Exists],
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            justification: 'Resolve an existing email identity.',
        ));
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('query-mail');
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->expects(self::once())
            ->method('condition')
            ->with('mail', 'member@example.test', 'CASE_INSENSITIVE_EQUALS')
            ->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn([7]);
        $repository = $this->createStub(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);

        $lookup = new AuditedUserIdentityLookup(new AuditedQueryFieldRead($registry, $ledger), $registry);

        self::assertTrue($lookup->mailExists($repository, 'member@example.test'));
    }

    public function test_login_prefers_an_exact_legacy_mail_match_before_canonical_fallback(): void
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
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('query-' . bin2hex(random_bytes(4)));
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $conditions = [];
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnCallback(
            static function (string $field, mixed $value, string $operator = '=') use (&$conditions, $query): EntityQueryInterface {
                $conditions[] = [$field, $value, $operator];
                return $query;
            },
        );
        $query->method('range')->willReturnSelf();
        $query->expects(self::exactly(2))->method('execute')->willReturnOnConsecutiveCalls([], [7]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $exact = $this->createStub(EntityInterface::class);
        $repository->expects(self::once())->method('find')->with('7')->willReturn($exact);

        $lookup = new AuditedUserIdentityLookup(new AuditedQueryFieldRead($registry, $ledger), $registry);

        self::assertSame($exact, $lookup->findActiveByLogin($repository, 'Member@Example.Test'));
        self::assertSame([
            ['name', 'Member@Example.Test', '='],
            ['status', 1, '='],
            ['mail', 'Member@Example.Test', '='],
            ['status', 1, '='],
        ], $conditions);
    }

    public function test_mail_recovery_refuses_an_active_case_variant_duplicate(): void
    {
        $registry = $this->identityRegistry();
        $conditions = [];
        $ranges = [];
        $query = $this->recordingQuery($conditions, $ranges);
        // Two active rows differ only by case. The submitted spelling matches
        // one of them exactly; recovery must still refuse the ambiguity.
        $query->expects(self::once())->method('execute')->willReturn([7, 8]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->expects(self::never())->method('find');

        $lookup = new AuditedUserIdentityLookup(
            new AuditedQueryFieldRead($registry, $this->silentLedger()),
            $registry,
        );

        self::assertNull($lookup->findActiveByMail($repository, 'member@example.test'));
        self::assertSame([
            ['mail', 'member@example.test', 'CASE_INSENSITIVE_EQUALS'],
            ['status', 1, '='],
        ], $conditions);
        self::assertSame([[0, 2]], $ranges);
    }

    public function test_mail_recovery_resolves_a_unique_canonical_match(): void
    {
        $registry = $this->identityRegistry();
        $conditions = [];
        $ranges = [];
        $query = $this->recordingQuery($conditions, $ranges);
        $query->expects(self::once())->method('execute')->willReturn([7]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $user = $this->createStub(EntityInterface::class);
        $repository->expects(self::once())->method('find')->with('7')->willReturn($user);

        $lookup = new AuditedUserIdentityLookup(
            new AuditedQueryFieldRead($registry, $this->silentLedger()),
            $registry,
        );

        // A spelling that differs in case from the stored row still resolves,
        // through the one canonical probe rather than an exact-first ladder.
        self::assertSame($user, $lookup->findActiveByMail($repository, 'MEMBER@Example.Test'));
        self::assertSame([
            ['mail', 'MEMBER@Example.Test', 'CASE_INSENSITIVE_EQUALS'],
            ['status', 1, '='],
        ], $conditions);
        self::assertSame([[0, 2]], $ranges);
    }

    public function test_login_refuses_ambiguous_case_variant_fallback(): void
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
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('query-' . bin2hex(random_bytes(4)));
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $ranges = [];
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnCallback(
            static function (int $offset, int $limit) use (&$ranges, $query): EntityQueryInterface {
                $ranges[] = [$offset, $limit];
                return $query;
            },
        );
        $query->expects(self::exactly(3))->method('execute')->willReturnOnConsecutiveCalls([], [], [7, 8]);
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->method('getQuery')->willReturn($query);
        $repository->expects(self::never())->method('find');

        $lookup = new AuditedUserIdentityLookup(new AuditedQueryFieldRead($registry, $ledger), $registry);

        self::assertNull($lookup->findActiveByLogin($repository, 'MEMBER@Example.Test'));
        self::assertSame([[0, 1], [0, 1], [0, 2]], $ranges);
    }
}
