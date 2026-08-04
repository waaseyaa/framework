<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Command\BearerTokenConsoleCommands;
use Waaseyaa\Auth\Token\Bearer\BearerTokenStoreInterface;
use Waaseyaa\Auth\Token\Bearer\DatabaseBearerTokenStore;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(BearerTokenConsoleCommands::class)]
final class BearerTokenConsoleCommandsTest extends TestCase
{
    private BearerTokenStoreInterface $store;

    /** @var array<string, HandlerCommand> */
    private array $commands = [];

    protected function setUp(): void
    {
        $this->store = new DatabaseBearerTokenStore(DBALDatabase::createSqlite());

        foreach (new BearerTokenConsoleCommands(fn(): BearerTokenStoreInterface => $this->store)->commands() as $command) {
            \assert($command instanceof HandlerCommand);
            $this->commands[(string) $command->getName()] = $command;
        }
    }

    private function runCommand(string $name, array $argv): CliTester
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): object
            {
                throw new \LogicException('These commands resolve nothing from the container.');
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        return CliTester::for($this->commands[$name], $container)->execute($argv);
    }

    #[Test]
    public function issue_prints_the_secret_once_and_the_record_summary(): void
    {
        $result = $this->runCommand('bearer-token:issue', [
            '42', '--scope', 'present guided content', '--label', 'ci-agent',
        ]);

        self::assertSame(0, $result->getExitCode(), $result->getOutput());
        self::assertMatchesRegularExpression('/mbt_[0-9a-f]{16}\.[0-9a-f]{64}/', $result->getStdout());
        self::assertStringContainsString('shown once', $result->getStdout());
        self::assertStringContainsString('present guided content', $result->getStdout());
    }

    #[Test]
    public function issue_refuses_a_non_numeric_account_uid(): void
    {
        $result = $this->runCommand('bearer-token:issue', ['not-a-uid', '--scope', 's']);

        self::assertSame(2, $result->getExitCode());
        self::assertStringContainsString('positive integer', $result->getStderr());
    }

    #[Test]
    public function issue_refuses_missing_scopes_with_a_clean_error(): void
    {
        $result = $this->runCommand('bearer-token:issue', ['42']);

        self::assertSame(2, $result->getExitCode());
        self::assertStringContainsString('scope', $result->getStderr());
    }

    #[Test]
    public function list_shows_fingerprints_but_never_a_secret(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, 'ci-agent');

        $result = $this->runCommand('bearer-token:list', []);

        self::assertSame(0, $result->getExitCode());
        self::assertStringContainsString($issued->record->id, $result->getStdout());
        self::assertStringContainsString($issued->record->fingerprint, $result->getStdout());
        self::assertStringContainsString('active', $result->getStdout());
        $secretHalf = substr($issued->secret, strlen($issued->record->id) + 1);
        self::assertStringNotContainsString($secretHalf, $result->getOutput());
    }

    #[Test]
    public function rotate_prints_the_successor_secret_and_kills_the_predecessor(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $result = $this->runCommand('bearer-token:rotate', [$issued->record->id]);

        self::assertSame(0, $result->getExitCode(), $result->getOutput());
        self::assertStringContainsString('Rotated ' . $issued->record->id, $result->getStdout());
        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));
    }

    #[Test]
    public function revoke_ends_authentication_and_reports_store_refusals_cleanly(): void
    {
        $issued = $this->store->issue(42, 'mcp:write', ['s'], 3600, '');

        $ok = $this->runCommand('bearer-token:revoke', [$issued->record->id]);
        self::assertSame(0, $ok->getExitCode());
        self::assertNull($this->store->verify($issued->secret, 'mcp:write'));

        $unknown = $this->runCommand('bearer-token:revoke', ['mbt_00000000000000ff']);
        self::assertSame(1, $unknown->getExitCode());
        self::assertStringContainsString('not recognised', $unknown->getStderr());
    }
}
