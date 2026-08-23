<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Auth\Token\AuthTokenRepository;
use Waaseyaa\Auth\Token\AuthTokenRepositoryInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\ComposerProjectFixture;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/** CLI, HTTP, and worker-shaped config must share the same derived or explicit HMAC key. */
#[CoversNothing]
final class AuthTokenSecretKernelParityTest extends TestCase
{
    private string $repoRoot;

    private string $projectRoot;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa-2500-' . bin2hex(random_bytes(6));
        foreach ([
            'APP_ENV',
            'APP_DEBUG',
            'WAASEYAA_APP_SECRET',
            'AUTH_TOKEN_SECRET',
            'WAASEYAA_DB',
        ] as $name) {
            $this->originalEnv[$name] = getenv($name);
        }
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        copy($this->repoRoot . '/VERSION', $this->projectRoot . '/VERSION');
        copy($this->repoRoot . '/composer.lock', $this->projectRoot . '/composer.lock');
        copy($this->repoRoot . '/composer.json', $this->projectRoot . '/composer.json');
        $this->putEnv('APP_ENV', 'testing');
        $this->putEnv('APP_DEBUG', '0');
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        EntityReadRuntime::installFieldRegistry(null);
        EntityReadRuntime::installGuard(null);
        foreach ($this->originalEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name]);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
                continue;
            }
            rmdir($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function http_and_cli_kernels_share_a_derived_key_that_is_not_the_master(): void
    {
        $master = random_bytes(32);
        $this->writeConfig();
        $this->putEnv('WAASEYAA_APP_SECRET', 'base64:' . base64_encode($master));
        $this->clearEnv('AUTH_TOKEN_SECRET');
        $this->installRuntimeSchema();

        $http = new HttpKernel($this->projectRoot);
        $http->bootForCli();
        $cli = new ConsoleKernel($this->projectRoot);
        $cli->bootForCli();

        $httpDatabase = $http->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $httpDatabase);
        $cliDatabase = $cli->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $cliDatabase);

        $httpRepo = $this->tokenRepository($http);
        $plain = $httpRepo->createToken(11, 'password_reset', 120);
        self::assertIsArray($this->tokenRepository($cli)->validateToken($plain, 'password_reset'));

        $raw = new AuthTokenRepository($httpDatabase, $master);
        $derived = new AuthTokenRepository(
            $httpDatabase,
            ApplicationSecret::fromEnvironmentValue(
                'base64:' . base64_encode($master),
                'testing',
            )->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
        );
        self::assertNull($raw->validateToken($plain, 'password_reset'));
        self::assertIsArray($derived->validateToken($plain, 'password_reset'));

        $dump = print_r($httpRepo, true);
        self::assertStringContainsString('[REDACTED]', $dump);
        self::assertStringNotContainsString($master, $dump);
        self::assertStringNotContainsString(base64_encode($master), $dump);

        $this->expectException(\LogicException::class);
        serialize($httpRepo);
    }

    #[Test]
    public function worker_shaped_explicit_secret_stays_independent_across_kernels(): void
    {
        $master = random_bytes(32);
        $explicit = 'worker-lane-explicit-token-secret!!';
        $this->writeConfig();
        $this->putEnv('WAASEYAA_APP_SECRET', 'base64:' . base64_encode($master));
        $this->putEnv('AUTH_TOKEN_SECRET', $explicit);
        $this->installRuntimeSchema();

        $http = new HttpKernel($this->projectRoot);
        $http->bootForCli();
        $cli = new ConsoleKernel($this->projectRoot);
        $cli->bootForCli();
        $httpDatabase = $http->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $httpDatabase);
        $cliDatabase = $cli->getDatabase();
        self::assertInstanceOf(DBALDatabase::class, $cliDatabase);

        $plain = $this->tokenRepository($http)->createToken(12, 'invite', 120);
        self::assertIsArray($this->tokenRepository($cli)->validateToken($plain, 'invite'));
        self::assertIsArray(new AuthTokenRepository($httpDatabase, $explicit)->validateToken($plain, 'invite'));
        self::assertNull(new AuthTokenRepository(
            $httpDatabase,
            ApplicationSecret::fromEnvironmentValue(
                'base64:' . base64_encode($master),
                'testing',
            )->derive(ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC),
        )->validateToken($plain, 'invite'));
    }

    #[Test]
    public function production_without_application_secret_refuses_to_boot(): void
    {
        $this->writeConfig('production');
        $this->putEnv('APP_ENV', 'production');
        $this->clearEnv('WAASEYAA_APP_SECRET');
        $this->clearEnv('AUTH_TOKEN_SECRET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WAASEYAA_APP_SECRET');
        new HttpKernel($this->projectRoot)->bootForCli();
    }

    private function installRuntimeSchema(): void
    {
        $database = DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite');
        RuntimeSchemaMigrations::auth($database);
        RuntimeSchemaMigrations::audit($database);
        RuntimeSchemaMigrations::broadcast($database);
        RuntimeSchemaMigrations::cache($database);
        RuntimeSchemaMigrations::oidc($database);
        $database->getConnection()->close();
        RuntimeSchemaMigrations::entitiesForProject($this->projectRoot);
    }

    private function tokenRepository(AbstractKernel $kernel): AuthTokenRepositoryInterface
    {
        foreach ($kernel->getProviders() as $provider) {
            if (!$provider instanceof ServiceProvider) {
                continue;
            }
            if (!isset($provider->getBindings()[AuthTokenRepositoryInterface::class])) {
                continue;
            }
            $resolved = $provider->resolve(AuthTokenRepositoryInterface::class);
            self::assertInstanceOf(AuthTokenRepositoryInterface::class, $resolved);

            return $resolved;
        }

        self::fail('AuthTokenRepositoryInterface was not bound.');
    }

    private function writeConfig(string $environment = 'testing'): void
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';
        $this->putEnv('WAASEYAA_DB', $databasePath);
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', <<<PHP
            <?php
            declare(strict_types=1);
            return [
                'database' => {$this->export($databasePath)},
                'environment' => {$this->export($environment)},
                'debug' => false,
                'app' => ['url' => 'http://localhost', 'name' => '2500'],
                'auth' => [
                    'token_secret' => getenv('AUTH_TOKEN_SECRET') ?: '',
                ],
            ];
            PHP);
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    private function putEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }

    private function clearEnv(string $name): void
    {
        putenv($name);
        unset($_ENV[$name]);
    }
}
