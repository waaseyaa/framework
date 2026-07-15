<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Audit\Integrity\LegacyCheckpointSignatureMigrator;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\CLI\Command\Audit\MigrateCheckpointSignaturesCommand;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(MigrateCheckpointSignaturesCommand::class)]
final class MigrateCheckpointSignaturesCommandTest extends TestCase
{
    #[Test]
    public function confirmation_is_required_before_any_signature_write(): void
    {
        [$tester, $db] = $this->tester();

        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('requires --confirm', $tester->getStderr());
        self::assertSame('', $db->getConnection()->fetchOne('SELECT signature FROM audit_checkpoint'));
    }

    #[Test]
    public function confirmed_command_authenticates_the_legacy_genesis(): void
    {
        [$tester, $db] = $this->tester();

        $tester->execute(['--confirm']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('Authenticated 1 legacy', $tester->getStdout());
        self::assertMatchesRegularExpression(
            '/^hmac-sha256\.hkdf-v1:[0-9a-f]{64}$/D',
            (string) $db->getConnection()->fetchOne('SELECT signature FROM audit_checkpoint'),
        );
    }

    /** @return array{CliTester, DBALDatabase} */
    private function tester(): array
    {
        $db = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($db)->ensureSchema();
        $command = new MigrateCheckpointSignaturesCommand(
            new LegacyCheckpointSignatureMigrator($db, random_bytes(32)),
        );
        $container = new class($command) implements ContainerInterface {
            public function __construct(private readonly object $command) {}
            public function get(string $id): mixed { return $this->command; }
            public function has(string $id): bool { return $id === MigrateCheckpointSignaturesCommand::class; }
        };
        $definition = new HandlerCommand(
            name: 'audit:migrate-checkpoint-signatures',
            description: 'test',
            options: [new HandlerOption(name: 'confirm', mode: HandlerOptionMode::None, description: 'test')],
            handler: [MigrateCheckpointSignaturesCommand::class, 'execute'],
        );

        return [CliTester::for($definition, $container), $db];
    }
}
