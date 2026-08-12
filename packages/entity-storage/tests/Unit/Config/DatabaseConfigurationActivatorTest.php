<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationAuthorizerInterface;
use Waaseyaa\Config\Activation\ConfigurationActivationConflictException;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationRequestReuseException;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\DatabaseConfigurationActivator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

final class DatabaseConfigurationActivatorTest extends TestCase
{
    private DBALDatabase $database;
    private ConfigurationAuthorityContext $context;
    private ConfigurationActivationAuthorizerInterface $authorizer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite(':memory:', 'testing');
        foreach ([
            '2026_08_12_000002_configuration_authority.php',
            '2026_08_12_000003_configuration_activation.php',
        ] as $migrationFile) {
            $migration = require dirname(__DIR__, 3) . '/migrations/' . $migrationFile;
            $migration->up(new SchemaBuilder($this->database->getConnection()));
        }
        $this->context = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:activation-test',
            syncPath: '/tmp/config-sync',
            selectorProvenance: ['testing'],
        );
        $this->authorizer = new class implements ConfigurationActivationAuthorizerInterface {
            public function authorize(ConfigurationActivationRequest $request, bool $deletes): void {}
        };
    }

    #[Test]
    public function firstActivationPublishesOneContentBoundOrderedHead(): void
    {
        $activator = $this->activator();
        $result = $activator->activate($this->request('request-1', null, [
            $this->file('system', 'site', ['name' => 'Waaseyaa']),
        ]));

        self::assertSame('committed', $result->status);
        self::assertSame(1, $result->token->activationSequence);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result->token->generationId);
        self::assertEquals($result->token, $activator->currentToken());
        self::assertSame(['system.site'], array_map(
            static fn(ConfigSyncFile $file): string => $file->ref(),
            iterator_to_array($activator->readGeneration($result->token)),
        ));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    #[Test]
    public function staleAndAbaExpectedTokensCannotCommit(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('request-a', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $second = $activator->activate($this->request('request-b', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
        $third = $activator->activate($this->request('request-a-again', $second->token, [$this->file('system', 'site', ['name' => 'A'])]));

        self::assertSame($first->token->generationId, $third->token->generationId);
        self::assertSame(3, $third->token->activationSequence);

        $this->expectException(ConfigurationActivationConflictException::class);
        $activator->activate($this->request('request-stale', $first->token, [$this->file('system', 'site', ['name' => 'C'])]));
    }

    #[Test]
    public function committedRetryIsIdempotentAndRequestReuseWithDifferentInputFails(): void
    {
        $activator = $this->activator();
        $request = $this->request('request-retry', null, [$this->file('system', 'site', ['name' => 'A'])]);
        $first = $activator->activate($request);
        $retry = $activator->activate($request);

        self::assertSame('already-committed', $retry->status);
        self::assertEquals($first->token, $retry->token);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));

        $this->expectException(ConfigurationActivationRequestReuseException::class);
        $activator->activate($this->request('request-retry', null, [$this->file('system', 'site', ['name' => 'different'])]));
    }

    #[Test]
    public function absenceRetainsWhileAnExplicitHashBoundTombstoneDeletes(): void
    {
        $activator = $this->activator();
        $site = $this->file('system', 'site', ['name' => 'Waaseyaa']);
        $role = $this->file('role', 'editor', ['label' => 'Editor']);
        $first = $activator->activate($this->request('request-full', null, [$site, $role]));

        $retained = $activator->activate($this->request('request-partial', $first->token, [
            $this->file('system', 'site', ['name' => 'Waaseyaa 2']),
        ]));
        self::assertSame(['role.editor', 'system.site'], $this->refs($activator, $retained->token));

        $deleted = $activator->activate($this->request(
            'request-delete',
            $retained->token,
            [],
            ['role.editor' => $role->contentHash()],
        ));
        self::assertSame(['system.site'], $this->refs($activator, $deleted->token));

        $this->expectException(ConfigurationActivationConflictException::class);
        $activator->activate($this->request(
            'request-stale-delete',
            $deleted->token,
            [],
            ['system.site' => str_repeat('0', 64)],
        ));
    }

    #[Test]
    public function missingAuthorizationRefusesBeforeCandidateStaging(): void
    {
        $authorizer = new class implements ConfigurationActivationAuthorizerInterface {
            public function authorize(ConfigurationActivationRequest $request, bool $deletes): void
            {
                throw new \DomainException('not authorized');
            }
        };
        $activator = new DatabaseConfigurationActivator($this->database, $this->context, $authorizer);

        try {
            $activator->activate($this->request('request-denied', null, [$this->file('system', 'site', ['name' => 'A'])]));
            self::fail('Unauthorized activation was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('not authorized', $exception->getMessage());
        }

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_candidate'));
        self::assertNull($activator->currentToken());
    }

    #[Test]
    public function failedLedgerAppendRollsBackCounterAndLeavesPriorHeadServing(): void
    {
        $activator = $this->activator();
        $first = $activator->activate($this->request('request-good', null, [$this->file('system', 'site', ['name' => 'A'])]));
        $this->database->query(<<<'SQL'
            CREATE TRIGGER reject_config_activation
            BEFORE INSERT ON waaseyaa_config_activation_v2
            BEGIN
                SELECT RAISE(ABORT, 'injected activation failure');
            END
            SQL);

        try {
            $activator->activate($this->request('request-fails', $first->token, [$this->file('system', 'site', ['name' => 'B'])]));
            self::fail('Injected activation failure was reported as success.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('injected activation failure', $exception->getMessage());
        }

        self::assertEquals($first->token, $activator->currentToken());
        self::assertSame(1, $this->scalar('SELECT last_sequence FROM waaseyaa_config_activation_counter'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM waaseyaa_config_activation_v2'));
    }

    private function activator(): DatabaseConfigurationActivator
    {
        return new DatabaseConfigurationActivator($this->database, $this->context, $this->authorizer);
    }

    /**
     * @param list<ConfigSyncFile> $files
     * @param array<string, string> $tombstones
     */
    private function request(
        string $requestId,
        ?ConfigurationActiveToken $expected,
        array $files,
        array $tombstones = [],
    ): ConfigurationActivationRequest {
        return new ConfigurationActivationRequest($requestId, $expected, $files, $tombstones);
    }

    /** @param array<string, mixed> $fields */
    private function file(string $entityType, string $entityId, array $fields): ConfigSyncFile
    {
        ksort($fields, SORT_STRING);

        return new ConfigSyncFile(
            entityType: $entityType,
            entityId: $entityId,
            uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
            dependencies: [],
            langcode: 'en',
            fields: $fields,
        );
    }

    /** @return list<string> */
    private function refs(DatabaseConfigurationActivator $activator, ConfigurationActiveToken $token): array
    {
        return array_map(
            static fn(ConfigSyncFile $file): string => $file->ref(),
            iterator_to_array($activator->readGeneration($token)),
        );
    }

    private function scalar(string $sql): int
    {
        foreach ($this->database->query($sql) as $row) {
            return (int) array_values($row)[0];
        }

        self::fail('Scalar query returned no row.');
    }
}
