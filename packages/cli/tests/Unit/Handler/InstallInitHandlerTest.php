<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Handler\InstallInitHandler;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Activation\ConfigurationActivationResult;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationGenesisActivatorInterface;
use Waaseyaa\Config\Activation\ConfigurationRollbackRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;

/**
 * The installation phase's decision table (#2428).
 *
 * The activation itself is proven against a real database in
 * DatabaseConfigurationActivatorTest; what matters here is that the handler
 * never mints a second generation, replays a completed install, and refuses
 * contradictory partial state instead of resolving it.
 */
#[CoversClass(InstallInitHandler::class)]
final class InstallInitHandlerTest extends TestCase
{
    private const string DATABASE_PATH = '/srv/waaseyaa/storage/site.sqlite';

    private ConfigurationAuthorityContext $authority;

    private string $syncPath;

    protected function setUp(): void
    {
        $this->syncPath = sys_get_temp_dir() . '/waaseyaa-install-sync-' . bin2hex(random_bytes(6)) . '/config/sync';
        $this->authority = new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: 'database:v1:test',
            syncPath: $this->syncPath,
            selectorProvenance: ['default'],
        );
    }

    /**
     * A site needs somewhere to receive an authored bundle (#2430), and it must
     * be empty: strict CFG-03 validation requires every member of a sync
     * directory to be a versioned config sync file, so a placeholder would make
     * the directory unsignable the moment it existed.
     */
    /** An existing directory is left exactly as it is, contents included. */
    #[Test]
    public function it_leaves_an_existing_sync_directory_untouched(): void
    {
        mkdir($this->syncPath, 0o755, true);
        file_put_contents($this->syncPath . '/system.site.yml', "existing\n");
        [$io, $output] = $this->io();

        new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator(null, null),
            genesis: $this->genesisActivator($this->token()),
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame("existing\n", file_get_contents($this->syncPath . '/system.site.yml'));
        self::assertStringNotContainsString('Created the configuration sync directory', $output->fetch());
    }

    /**
     * A file where the directory belongs is reported and left alone. Installation
     * has no business deleting whatever an operator put there.
     */
    #[Test]
    public function it_reports_a_sync_path_that_is_not_a_directory(): void
    {
        mkdir(dirname($this->syncPath), 0o755, true);
        file_put_contents($this->syncPath, 'not a directory');
        [$io, $output] = $this->io();

        $exit = new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator(null, null),
            genesis: $this->genesisActivator($this->token()),
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame(0, $exit, 'A surprising sync path must not block installation.');
        self::assertStringContainsString('is not a directory; leaving it alone', $output->fetch());
        self::assertSame('not a directory', file_get_contents($this->syncPath));
    }

    #[Test]
    public function it_creates_an_empty_configuration_sync_directory(): void
    {
        self::assertDirectoryDoesNotExist($this->syncPath);

        $genesis = $this->genesisActivator($this->token());
        [$io, $output] = $this->io();

        $exit = new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator(null, null),
            genesis: $genesis,
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame(0, $exit);
        self::assertDirectoryExists($this->syncPath);
        self::assertStringContainsString('Created the configuration sync directory', $output->fetch());
        self::assertSame(
            [],
            array_values(array_diff((array) scandir($this->syncPath), ['.', '..'])),
            'Installation must leave the sync directory empty.',
        );
        self::assertNotSame([], $genesis->requests);
    }

    #[Test]
    public function it_activates_the_initial_generation_on_a_fresh_site(): void
    {
        $prepared = false;
        $genesis = $this->genesisActivator($this->token());
        [$io, $output] = $this->io();

        $exit = new InstallInitHandler(
            prepareSchema: static function () use (&$prepared): void { $prepared = true; },
            activator: $this->activator(null, null),
            genesis: $genesis,
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame(0, $exit);
        self::assertTrue($prepared, 'Schema preparation must run before activation.');
        self::assertSame([InstallInitHandler::requestId($this->authority)], $genesis->requests);
        $text = $output->fetch();
        self::assertStringContainsString('Activated generation', $text);
        self::assertStringContainsString('Installation is complete.', $text);
    }

    #[Test]
    public function it_is_idempotent_when_a_generation_is_already_active(): void
    {
        $genesis = $this->genesisActivator($this->token());
        [$io, $output] = $this->io();

        $exit = new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator($this->token(), null),
            genesis: $genesis,
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame(0, $exit);
        self::assertSame([], $genesis->requests, 'A second install must not activate anything.');
        self::assertStringContainsString('Configuration already initialized', $output->fetch());
    }

    #[Test]
    public function it_refuses_a_committed_request_with_no_active_generation(): void
    {
        $committed = new ConfigurationActivationResult(
            status: 'committed',
            token: $this->token(),
            requestId: InstallInitHandler::requestId($this->authority),
            planHash: str_repeat('d', 64),
        );
        $genesis = $this->genesisActivator($this->token());
        [$io, $output] = $this->io();

        $exit = new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator(null, $committed),
            genesis: $genesis,
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        self::assertSame(1, $exit, 'Contradictory partial state must refuse, not activate.');
        self::assertSame([], $genesis->requests);
        self::assertStringContainsString('Refusing', $output->fetch());
    }

    #[Test]
    public function the_request_id_is_stable_per_site_and_differs_between_sites(): void
    {
        $other = new ConfigurationAuthorityContext(
            authorityId: str_repeat('b', 64),
            databaseIdentity: 'database:v1:other',
            syncPath: '/tmp/config-sync',
            selectorProvenance: ['default'],
        );

        self::assertSame(
            InstallInitHandler::requestId($this->authority),
            InstallInitHandler::requestId($this->authority),
            'A retry must correlate with its own prior attempt.',
        );
        self::assertNotSame(
            InstallInitHandler::requestId($this->authority),
            InstallInitHandler::requestId($other),
        );
        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',
            InstallInitHandler::requestId($this->authority),
            'The id must satisfy ConfigurationActivationRequest.',
        );
    }

    /**
     * Every configuration lifecycle row is keyed by authority, and
     * DatabaseBootstrapper resolves the database path from config BEFORE
     * WAASEYAA_DB. An operator who believes they are installing into a copied
     * database therefore has no way to discover they are not, which is how
     * #2545 was misdiagnosed. Reporting identity is the whole remedy, so it is
     * asserted on the run that does the least: the already-initialized path.
     */
    #[Test]
    public function it_reports_the_resolved_authority_before_doing_anything(): void
    {
        [$io, $output] = $this->io();

        new InstallInitHandler(
            prepareSchema: static function (): void {},
            activator: $this->activator($this->token(), null),
            genesis: $this->genesisActivator($this->token()),
            authority: $this->authority,
            databasePath: self::DATABASE_PATH,
        )->execute($io);

        $written = $output->fetch();
        self::assertStringContainsString('Configuration authority ' . str_repeat('a', 64), $written);
        self::assertStringContainsString('database path ' . self::DATABASE_PATH, $written);
        self::assertStringContainsString('database identity database:v1:test', $written);
        self::assertStringContainsString($this->syncPath, $written);
        $identityPosition = strpos($written, 'Configuration authority ');
        $migrationPosition = strpos($written, 'Applying migrations');
        self::assertIsInt($identityPosition);
        self::assertIsInt($migrationPosition);
        self::assertLessThan($migrationPosition, $identityPosition, 'Resolved identity must be printed before schema work starts.');
    }

    /** @return array{SymfonyCommandIO, BufferedOutput} */
    private function io(): array
    {
        $output = new BufferedOutput();

        return [new SymfonyCommandIO(new ArrayInput([]), $output), $output];
    }

    private function token(): ConfigurationActiveToken
    {
        return new ConfigurationActiveToken(str_repeat('c', 64), 1);
    }

    private function activator(?ConfigurationActiveToken $current, ?ConfigurationActivationResult $committed): ConfigurationActivatorInterface
    {
        return new class ($current, $committed) implements ConfigurationActivatorInterface {
            public function __construct(
                private readonly ?ConfigurationActiveToken $current,
                private readonly ?ConfigurationActivationResult $committed,
            ) {}

            public function activate(ConfigurationActivationRequest $request): ConfigurationActivationResult
            {
                throw new \LogicException('install:init must never use ordinary activation.');
            }

            public function rollback(ConfigurationRollbackRequest $request): ConfigurationActivationResult
            {
                throw new \LogicException('install:init must never roll back.');
            }

            public function committedResult(string $requestId): ?ConfigurationActivationResult
            {
                return $this->committed;
            }

            public function currentToken(): ?ConfigurationActiveToken
            {
                return $this->current;
            }

            public function readGeneration(ConfigurationActiveToken $token): iterable
            {
                return [];
            }
        };
    }

    private function genesisActivator(ConfigurationActiveToken $token): ConfigurationGenesisActivatorInterface
    {
        return new class ($token) implements ConfigurationGenesisActivatorInterface {
            /** @var list<string> */
            public array $requests = [];

            public function __construct(private readonly ConfigurationActiveToken $token) {}

            public function activateGenesis(string $requestId): ConfigurationActivationResult
            {
                $this->requests[] = $requestId;

                return new ConfigurationActivationResult(
                    status: 'committed',
                    token: $this->token,
                    requestId: $requestId,
                    planHash: str_repeat('e', 64),
                );
            }
        };
    }
}
