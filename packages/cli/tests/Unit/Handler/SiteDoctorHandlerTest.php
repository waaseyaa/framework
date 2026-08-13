<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\CLI\Handler\SiteDoctorHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;

final class SiteDoctorHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }

    public function testStrictDoctorDiscoversRepoWideBypassAndEmitsCanonicalReport(): void
    {
        $root = $this->fixture();
        mkdir($root . '/app/Deep', 0o777, true);
        file_put_contents($root . '/app/Deep/Store.php', "<?php\n\$db = new PDO('sqlite::memory:');\n");
        $tester = $this->tester($root);
        $tester->execute(["--project-root={$root}", '--strict', '--format=json']);

        self::assertSame(1, $tester->getExitCode());
        $report = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('waaseyaa.site-doctor', $report['schema']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $report['composer_lock_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $report['generated_metadata_sha256']);
        self::assertSame('SITE100_RAW_DATABASE_CONSTRUCTION', $report['findings'][0]['id']);
        self::assertSame('app/Deep/Store.php', $report['findings'][0]['path']);
        self::assertSame('FAILED', $report['summary']);
    }

    public function testDoctorRefusesSubstitutedGeneratedArtifactAndLockProvenance(): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/tests/Architecture/SiteContractTest.php', "<?php\n// substituted\n");
        file_put_contents($root . '/composer.lock', "{\"changed\":true}\n");
        $tester = $this->tester($root);
        $tester->execute(["--project-root={$root}", '--strict']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('SITE010_GENERATED_ARTIFACT_DRIFT', $tester->getStdout());
        self::assertStringContainsString('SITE011_COMPOSER_LOCK_DRIFT', $tester->getStdout());
        self::assertStringNotContainsString('OK', $tester->getStdout());
    }

    public function testProviderRegistersInitAndDoctorCommands(): void
    {
        $commands = iterator_to_array(new SiteServiceProvider($this->fixture())->consoleCommands());
        self::assertSame(['site:init', 'site:doctor'], array_map(static fn($command): string => $command->getName(), $commands));
    }

    public function testCountPreservingGeneratedMetadataSubstitutionCannotPass(): void
    {
        $root = $this->fixture();
        $path = $root . '/.waaseyaa/generated.json';
        $metadata = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metadata['artifacts'][0]['path'] = 'config/substituted.php';
        file_put_contents($path, \Waaseyaa\SiteContract\CanonicalJson::encode($metadata) . "\n");
        $tester = $this->tester($root);
        $tester->execute(["--project-root={$root}", '--strict']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('SITE010_GENERATED_ARTIFACT_DRIFT', $tester->getStdout());
    }

    public function test_nested_tests_named_directory_cannot_hide_shipped_application_source(): void
    {
        $root = $this->fixture();
        mkdir($root . '/app/Legacy/tests', 0o777, true);
        file_put_contents($root . '/app/Legacy/tests/Store.php', "<?php\n\$db = new SQLite3('/tmp/hidden.sqlite');\n");
        $tester = $this->tester($root);
        $tester->execute(["--project-root={$root}", '--strict']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('app/Legacy/tests/Store.php', $tester->getStdout());
    }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_doctor_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
        $site = new \Waaseyaa\SiteContract\Generation\SiteArtifactRenderer()->render(new \Waaseyaa\SiteContract\SiteManifestParser()->parse($manifest));
        foreach ($site->artifacts as $artifact) {
            $path = $root . '/' . $artifact->path;
            is_dir(dirname($path)) || mkdir(dirname($path), 0o777, true);
            file_put_contents($path, $artifact->content);
            chmod($path, $artifact->mode);
        }

        return $root;
    }

    private function tester(string $root): CliTester
    {
        $provider = new SiteServiceProvider($root);
        $command = iterator_to_array($provider->consoleCommands())[1];
        $handler = new SiteDoctorHandler($root);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SiteDoctorHandler $handler) {}
            public function get(string $id): mixed
            {
                return $id === SiteDoctorHandler::class ? $this->handler : throw new \RuntimeException('Unexpected service.');
            }
            public function has(string $id): bool
            {
                return $id === SiteDoctorHandler::class;
            }
        };

        return CliTester::for($command, $container);
    }

    private function manifest(): string
    {
        return <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application: {id: example, name: Example, canonical_origin: {config_key: APP_ORIGIN}}
            framework: {revision_policy: exact-lock, observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}
            content_types: [{id: page, canonical_route: '/{slug}'}]
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;
    }
}
