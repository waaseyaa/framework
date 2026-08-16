<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\AuditServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

#[CoversNothing]
final class AuditRuntimeSchemaAuthorityTest extends TestCase
{
    #[Test]
    public function audit_provider_boot_refuses_missing_schema_without_creating_tables(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = $this->provider($database);
        $this->expectSchemaRefusal(static fn() => $provider->boot());
        self::assertSame([], $this->tables($database));
    }

    #[Test]
    public function strict_audit_ledger_resolution_refuses_missing_schema_without_creating_tables(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = $this->provider($database);
        $this->expectSchemaRefusal(static fn() => $provider->resolve(StrictAuditLedgerInterface::class));
        self::assertSame([], $this->tables($database));
    }

    #[Test]
    public function approval_store_resolution_refuses_missing_schema_without_creating_tables(): void
    {
        $database = DBALDatabase::createSqlite();
        $provider = $this->provider($database);
        $this->expectSchemaRefusal(static fn() => $provider->resolve(OperationApprovalStoreInterface::class));
        self::assertSame([], $this->tables($database));
    }

    private function provider(DBALDatabase $database): AuditServiceProvider
    {
        $provider = new AuditServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->setKernelServices(new KernelServicesFixture([
            DatabaseInterface::class => $database,
            ApplicationSecret::class => ApplicationSecret::fromEnvironmentValue(null, 'testing'),
        ]));
        $provider->register();
        return $provider;
    }

    /** @param \Closure(): mixed $operation */
    private function expectSchemaRefusal(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Missing audit schema must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
        }
    }

    /** @return list<string> */
    private function tables(DBALDatabase $database): array
    {
        return array_values(array_map(
            static fn(array $row): string => (string) $row['name'],
            iterator_to_array($database->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")),
        ));
    }
}
