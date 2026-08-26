<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\MediaFieldRead;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\DbInitHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

#[CoversNothing]
final class AuthenticatedMediaFieldReadFreshInstallTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_media_field_read_' . bin2hex(random_bytes(6));
        foreach (['config', 'src', 'storage/files/members'] as $directory) {
            self::assertTrue(mkdir($this->projectRoot . '/' . $directory, 0o755, true));
        }
        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);
        self::assertTrue(copy(__DIR__ . '/Fixtures/MediaFieldReadRegressionProvider.php', $this->projectRoot . '/src/MediaFieldReadRegressionProvider.php'));
        self::assertTrue(copy(__DIR__ . '/Fixtures/MemberRestrictedMediaAccessPolicy.php', $this->projectRoot . '/src/MemberRestrictedMediaAccessPolicy.php'));
        $sourcePsr4 = var_export($this->repoRoot . '/vendor/composer/autoload_psr4.php', true);
        $appSource = var_export($this->projectRoot . '/src', true);
        file_put_contents(
            $this->projectRoot . '/vendor/composer/autoload_psr4.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
                . "return ['MediaReadRegression\\\\' => [{$appSource}]] + require {$sourcePsr4};\n",
        );
        $repoAutoload = var_export($this->repoRoot . '/vendor/autoload.php', true);
        $provider = var_export(__DIR__ . '/Fixtures/MediaFieldReadRegressionProvider.php', true);
        $policy = var_export(__DIR__ . '/Fixtures/MemberRestrictedMediaAccessPolicy.php', true);
        file_put_contents(
            $this->projectRoot . '/vendor/autoload.php',
            "<?php\n\ndeclare(strict_types=1);\n\n"
                . "\$loader = require {$repoAutoload};\n"
                . "require_once {$provider};\n"
                . "require_once {$policy};\n\n"
                . "return \$loader;\n",
        );
        require_once __DIR__ . '/Fixtures/MediaFieldReadRegressionProvider.php';
        require_once __DIR__ . '/Fixtures/MemberRestrictedMediaAccessPolicy.php';
        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/media-field-read-regression',
            'autoload' => ['psr-4' => ['MediaReadRegression\\' => 'src/']],
            'extra' => ['waaseyaa' => [
                'providers' => [\MediaReadRegression\MediaFieldReadRegressionProvider::class],
                'policies' => [\MediaReadRegression\MemberRestrictedMediaAccessPolicy::class],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->config());
        file_put_contents($this->projectRoot . '/storage/files/members/governance.pdf', "%PDF-1.4\nBAND-GOVERNANCE-DOCUMENT\n%%EOF\n");

        $this->initializeDatabase();
        $this->seedDatabase();
    }

    protected function tearDown(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        EntityReadRuntime::installFieldRegistry(null);
        if (is_dir($this->projectRoot)) {
            new Filesystem()->remove($this->projectRoot);
        }
    }

    #[Test]
    public function authenticated_band_member_can_list_administer_and_download_migrated_media(): void
    {
        $pdo = new \PDO('sqlite:' . $this->projectRoot . '/storage/waaseyaa.sqlite');
        $stored = (string) $pdo->query('SELECT _data FROM media WHERE mid = 389')->fetchColumn();
        self::assertStringContainsString('Member governance description', $stored, 'Fixture must retain the migrated _data shape.');

        $api = $this->request('/api/media');
        $admin = $this->request('/admin/_surface/media');
        $download = $this->request('/media/389/download');

        self::assertSame(200, $api['status'], $api['body']);
        self::assertStringContainsString('Band governance document', $api['body']);
        self::assertStringContainsString('Member governance description', $api['body']);
        self::assertStringNotContainsString('FieldReadDenied', $api['body']);
        self::assertStringNotContainsString('FieldReadGuard.php', $api['body']);
        self::assertStringNotContainsString('"trace"', $api['body']);

        self::assertSame(200, $admin['status'], $admin['body']);
        $adminPayload = json_decode($admin['body'], true, flags: JSON_THROW_ON_ERROR);
        $adminAttributes = $adminPayload['data']['entities'][0]['attributes'] ?? [];
        self::assertSame('Band governance document', $adminAttributes['name'] ?? null);
        self::assertSame(42, $adminAttributes['uid'] ?? null);
        self::assertSame('2024-03-09T16:00:00+00:00', $adminAttributes['created'] ?? null);
        self::assertSame('2024-03-09T17:00:00+00:00', $adminAttributes['changed'] ?? null);

        self::assertSame(200, $download['status'], $download['body']);
        self::assertStringContainsString('BAND-GOVERNANCE-DOCUMENT', $download['body']);
    }

    #[Test]
    public function authenticated_iframe_view_uses_the_real_kernel_route_and_conceals_denial(): void
    {
        $view = $this->request('/media/389/view');
        $denied = $this->request('/media/389/view', uid: 43);
        $missing = $this->request('/media/999/view');

        self::assertSame(200, $view['status']);
        self::assertSame('application/pdf', $view['headers']['content-type'][0] ?? null);
        self::assertSame('inline; filename="governance.pdf"', $view['headers']['content-disposition'][0] ?? null);
        self::assertSame('SAMEORIGIN', $view['headers']['x-frame-options'][0] ?? null);
        self::assertSame('nosniff', $view['headers']['x-content-type-options'][0] ?? null);
        self::assertStringContainsString('BAND-GOVERNANCE-DOCUMENT', $view['body']);

        self::assertSame($this->concealmentFingerprint($missing), $this->concealmentFingerprint($denied));
    }

    private function initializeDatabase(): void
    {
        $handler = new DbInitHandler($this->projectRoot);
        $command = new HandlerCommand(
            name: 'db:init',
            description: 'Initialize media regression database.',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Dry run.'),
                new HandlerOption(name: 'no-sync-schema', mode: HandlerOptionMode::None, description: 'Skip schema sync.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('Not found: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $tester = CliTester::for($command, $container);
        $tester->execute([]);
        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
    }

    private function seedDatabase(): void
    {
        $kernel = new HttpKernel($this->projectRoot);
        new \ReflectionMethod(AbstractKernel::class, 'boot')->invoke($kernel);
        $manager = $kernel->getEntityTypeManager();
        $users = $manager->getRepository('user');
        $users->save($users->create([
            'uid' => 42,
            'bundle' => 'user',
            'name' => 'band-member',
            'roles' => ['band_member'],
            'permissions' => ['administer content', 'access media'],
            'status' => true,
        ]), validate: false);
        $users->save($users->create([
            'uid' => 43,
            'bundle' => 'user',
            'name' => 'authenticated-viewer',
            'roles' => ['authenticated'],
            'permissions' => ['access media'],
            'status' => true,
        ]), validate: false);
        $types = $manager->getRepository('media_type');
        $types->save($types->create(['id' => 'members_document', 'label' => 'Members document']), validate: false);
        $media = $manager->getRepository('media');
        $media->save($media->create([
            'mid' => 389,
            'bundle' => 'members_document',
            'name' => 'Band governance document',
            'description' => 'Member governance description',
            'source_uri' => 'public://members/governance.pdf',
            'uid' => 42,
            'status' => true,
            'created' => 1710000000,
            'changed' => 1710003600,
        ]), validate: false);
        $database = $kernel->getDatabase();
        if ($database instanceof DBALDatabase) {
            $database->getConnection()->close();
        }
    }

    /** @return array{status:int,headers:array<string,list<string|null>>,body:string} */
    private function request(string $uri, int $uid = 42): array
    {
        $command = sprintf(
            '%s %s %s %s %d 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/Fixtures/authenticated_http_runner.php'),
            escapeshellarg($this->projectRoot),
            escapeshellarg($uri),
            $uid,
        );
        $output = shell_exec($command);
        self::assertNotNull($output);
        $lines = array_values(array_filter(preg_split('/\R/', trim($output)) ?: []));
        $payload = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
        $body = base64_decode((string) ($payload['body_base64'] ?? ''), true);
        self::assertIsString($body);

        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];

        return ['status' => (int) ($payload['status'] ?? 0), 'headers' => $headers, 'body' => $body];
    }

    /** @param array{status:int,headers:array<string,list<string|null>>,body:string} $response */
    private function concealmentFingerprint(array $response): array
    {
        $headers = $response['headers'];
        unset(
            $headers['date'],
            $headers['x-debug-time'],
            $headers['x-debug-memory'],
            $headers['x-ratelimit-remaining'],
        );

        return ['status' => $response['status'], 'headers' => $headers, 'body' => $response['body']];
    }

    private function config(): string
    {
        $database = var_export($this->projectRoot . '/storage/waaseyaa.sqlite', true);
        $files = var_export($this->projectRoot . '/storage/files', true);

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    'database' => {$database},\n"
            . "    'environment' => 'testing',\n"
            . "    'debug' => true,\n"
            . "    'files_root' => {$files},\n"
            . "    'app' => ['url' => 'http://localhost', 'name' => 'Media regression'],\n"
            . "];\n";
    }

}
