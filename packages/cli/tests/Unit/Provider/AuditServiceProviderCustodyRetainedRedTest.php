<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Integrity\AuditCheckpointCustody;
use Waaseyaa\CLI\Command\Audit\MigrateCheckpointSignaturesCommand;
use Waaseyaa\CLI\Command\Audit\PruneCommand;
use Waaseyaa\CLI\Command\Audit\VerifyCommand;
use Waaseyaa\CLI\Provider\AuditServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Testing\Kernel\KernelServicesFixture;

/** Retained-red proof that operator audit commands consume composed custody. */
final class AuditServiceProviderCustodyRetainedRedTest extends TestCase
{
    #[Test]
    public function command_wiring_requires_custody_but_not_application_secret_authority(): void
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $custody = new AuditCheckpointCustody(legacyKey: random_bytes(32));
        $query = new class implements AuditQueryInterface {
            public function findBy(AuditQuery $query): iterable
            {
                return [];
            }

            public function count(AuditQuery $query): int
            {
                return 0;
            }
        };
        $writer = new class implements AuditWriterInterface {
            public function record(AuditEventDescriptor $descriptor): void {}
        };
        $provider = new AuditServiceProvider();
        $provider->setKernelContext('', [], []);
        $provider->setKernelServices(new KernelServicesFixture([
            DatabaseInterface::class => $database,
            AuditCheckpointCustody::class => $custody,
            AuditQueryInterface::class => $query,
            AuditWriterInterface::class => $writer,
        ]));
        $provider->register();

        self::assertInstanceOf(PruneCommand::class, $provider->resolve(PruneCommand::class));
        self::assertInstanceOf(VerifyCommand::class, $provider->resolve(VerifyCommand::class));
        self::assertInstanceOf(
            MigrateCheckpointSignaturesCommand::class,
            $provider->resolve(MigrateCheckpointSignaturesCommand::class),
        );
    }
}
