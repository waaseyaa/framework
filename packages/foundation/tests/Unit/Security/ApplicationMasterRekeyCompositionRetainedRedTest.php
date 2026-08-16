<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyComposition;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;

/** Retained-red proof for exact, activation-aware CFG-04 provider composition. */
final class ApplicationMasterRekeyCompositionRetainedRedTest extends TestCase
{
    #[Test]
    public function an_install_with_no_active_contributors_has_no_fabricated_registry(): void
    {
        self::assertNull(ApplicationMasterRekeyComposition::fromProviders(
            DBALDatabase::createSqlite(':memory:'),
            [new SyntheticApplicationMasterContributionProvider([])],
        ));
    }

    #[Test]
    public function active_contributions_freeze_deterministically_independent_of_provider_order(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $audit = $this->contribution(
            $database,
            'audit-checkpoint-v1',
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            'waaseyaa/audit',
            ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
        );
        $cache = $this->contribution(
            $database,
            'cache-payload-v1',
            ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
            'waaseyaa/cache',
            ApplicationMasterPurposeStrategy::InvalidateRebuildable,
        );

        $first = ApplicationMasterRekeyComposition::fromProviders($database, [
            new SyntheticApplicationMasterContributionProvider([$cache]),
            new SyntheticApplicationMasterContributionProvider([$audit]),
        ]);
        $second = ApplicationMasterRekeyComposition::fromProviders($database, [
            new SyntheticApplicationMasterContributionProvider([$audit]),
            new SyntheticApplicationMasterContributionProvider([$cache]),
        ]);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($first->purposes()->isFrozen());
        self::assertSame([
            ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
            ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
        ], $first->purposes()->purposeIds());
        self::assertSame(['audit-checkpoint-v1', 'cache-payload-v1'], array_map(
            static fn(ApplicationMasterRekeyAdapterInterface $adapter): string => $adapter->id(),
            $first->adapters(),
        ));
        self::assertSame($first->purposes()->checksum(), $second->purposes()->checksum());
    }

    #[Test]
    public function duplicate_purpose_ownership_fails_closed(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        ApplicationMasterRekeyComposition::fromProviders($database, [
            new SyntheticApplicationMasterContributionProvider([$this->contribution(
                $database,
                'audit-owner-a',
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                'waaseyaa/audit',
                ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            )]),
            new SyntheticApplicationMasterContributionProvider([$this->contribution(
                $database,
                'audit-owner-b',
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                'waaseyaa/audit',
                ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            )]),
        ]);
    }

    #[Test]
    public function duplicate_adapter_ownership_fails_closed(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        ApplicationMasterRekeyComposition::fromProviders($database, [
            new SyntheticApplicationMasterContributionProvider([$this->contribution(
                $database,
                'shared-owner-v1',
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                'waaseyaa/audit',
                ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            )]),
            new SyntheticApplicationMasterContributionProvider([$this->contribution(
                $database,
                'shared-owner-v1',
                ApplicationSecret::PURPOSE_CACHE_PAYLOAD_HMAC,
                'waaseyaa/cache',
                ApplicationMasterPurposeStrategy::InvalidateRebuildable,
            )]),
        ]);
    }

    #[Test]
    public function a_distinct_database_object_is_not_the_kernel_transaction_authority(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $alias = DBALDatabase::createSqlite(':memory:');

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        ApplicationMasterRekeyComposition::fromProviders($database, [
            new SyntheticApplicationMasterContributionProvider([$this->contribution(
                $alias,
                'audit-checkpoint-v1',
                ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                'waaseyaa/audit',
                ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
            )]),
        ]);
    }

    #[Test]
    public function a_contribution_must_bind_the_adapters_exact_purpose_roster(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $adapter = new SyntheticCompositionRekeyAdapter(
            $database,
            'joint-owner-v1',
            [
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
                ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_LOOKUP,
            ],
        );

        $this->expectException(ApplicationMasterRekeyConflictException::class);
        new ApplicationMasterRekeyContribution($adapter, [new ApplicationMasterPurposePolicy(
            id: ApplicationSecret::PURPOSE_OIDC_ACCESS_TOKEN_ENCRYPTION,
            ownerPackage: 'waaseyaa/oidc',
            strategy: ApplicationMasterPurposeStrategy::ReencryptCiphertext,
            maximumLifetimeSeconds: 3_600,
            retentionSeconds: 86_400,
            adapterId: 'joint-owner-v1',
            rollbackBehavior: 'reverse-cas',
        )]);
    }

    private function contribution(
        DatabaseInterface $database,
        string $adapterId,
        string $purposeId,
        string $ownerPackage,
        ApplicationMasterPurposeStrategy $strategy,
    ): ApplicationMasterRekeyContribution {
        return new ApplicationMasterRekeyContribution(
            new SyntheticCompositionRekeyAdapter($database, $adapterId, [$purposeId]),
            [new ApplicationMasterPurposePolicy(
                id: $purposeId,
                ownerPackage: $ownerPackage,
                strategy: $strategy,
                maximumLifetimeSeconds: 3_600,
                retentionSeconds: 86_400,
                adapterId: $adapterId,
                rollbackBehavior: 'synthetic-complete',
            )],
        );
    }
}

final readonly class SyntheticApplicationMasterContributionProvider implements ProvidesApplicationMasterRekeyContributionsInterface
{
    /** @param list<ApplicationMasterRekeyContribution> $contributions */
    public function __construct(private array $contributions) {}

    public function applicationMasterRekeyContributions(): iterable
    {
        return $this->contributions;
    }
}

final readonly class SyntheticCompositionRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    /** @param list<string> $purposes */
    public function __construct(
        private DatabaseInterface $database,
        private string $adapterId,
        private array $purposes,
    ) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return $this->adapterId;
    }

    public function purposeIds(): array
    {
        return $this->purposes;
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return new ApplicationMasterInventorySnapshot(hash('sha256', 'synthetic-snapshot'), 0);
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new \LogicException('Synthetic composition adapters never execute.');
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return [];
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new \LogicException('Synthetic composition adapters never execute.');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return new ApplicationMasterInventorySnapshot(hash('sha256', 'synthetic-rollback-snapshot'), 0);
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return [];
    }
}
