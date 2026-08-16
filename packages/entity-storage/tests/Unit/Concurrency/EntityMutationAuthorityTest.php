<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;

final class EntityMutationAuthorityTest extends TestCase
{
    private DBALDatabase $database;
    private EntityMutationAuthority $authority;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_entity_mutation_authority (
                storage_authority VARCHAR(191) NOT NULL,
                tenant_id VARCHAR(191) NOT NULL,
                entity_type VARCHAR(191) NOT NULL,
                entity_id VARCHAR(191) NOT NULL,
                aggregate_version INTEGER NOT NULL,
                mutation_tag VARCHAR(64) NOT NULL,
                lifecycle_state VARCHAR(16) NOT NULL,
                PRIMARY KEY (storage_authority, tenant_id, entity_type, entity_id)
            )
            SQL);
        $this->authority = new EntityMutationAuthority($this->database, 'primary');
    }

    #[Test]
    public function twoReadersProduceExactlyOneWinningClaim(): void
    {
        $first = $this->authority->create('community-a', 'node', '42');
        $second = $this->authority->load('community-a', 'node', '42');
        self::assertNotNull($second);

        $winner = $this->authority->claim($first);
        self::assertSame(2, $winner->aggregateVersion);
        self::assertNotSame($first->toOpaqueString(), $winner->toOpaqueString());

        $this->expectException(EntityMutationConflictException::class);
        $this->authority->claim($second);
    }

    #[Test]
    public function tokenCannotBeTransplantedAcrossTenantOrIdentity(): void
    {
        $token = $this->authority->create('community-a', 'node', '42');

        foreach ([
            ['community-b', 'node', '42'],
            ['community-a', 'user', '42'],
            ['community-a', 'node', '43'],
        ] as [$tenant, $type, $id]) {
            $this->authority->create($tenant, $type, $id);
        }

        foreach ([
            ['community-b', 'node', '42'],
            ['community-a', 'user', '42'],
            ['community-a', 'node', '43'],
        ] as [$tenant, $type, $id]) {
            try {
                $this->authority->claimForIdentity($tenant, $type, $id, $token);
                self::fail('A transplanted token was accepted.');
            } catch (EntityMutationConflictException $exception) {
                self::assertNull($exception->currentToken, 'Conflict must not disclose current authority.');
            }
        }
    }

    #[Test]
    public function tombstoneAndRecreateAdvanceAuthorityWithoutAba(): void
    {
        $created = $this->authority->create('community-a', 'node', '42');
        $tombstone = $this->authority->tombstone($created);
        self::assertSame(2, $tombstone->aggregateVersion);
        self::assertSame('tombstone', $this->authority->state($tombstone));

        $recreated = $this->authority->recreate('community-a', 'node', '42', $tombstone);
        self::assertSame(3, $recreated->aggregateVersion);
        self::assertSame('active', $this->authority->state($recreated));

        foreach ([$created, $tombstone] as $stale) {
            try {
                $this->authority->claim($stale);
                self::fail('A pre-recreation token was accepted.');
            } catch (EntityMutationConflictException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function duplicateCreateIsConflictRatherThanLastWriteWins(): void
    {
        $this->authority->create('community-a', 'node', '42');

        $this->expectException(EntityMutationConflictException::class);
        $this->authority->create('community-a', 'node', '42');
    }
}
