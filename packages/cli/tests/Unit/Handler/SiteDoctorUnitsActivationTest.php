<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\SiteDoctorHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyRequest;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteDoctorHandler::class)]
#[CoversClass(SiteServiceProvider::class)]
final class SiteDoctorUnitsActivationTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    #[Test]
    public function handlerAcceptsAValidManagedNonRootUnitPublishedThroughPublicApply(): void
    {
        $root = $this->fixture();
        $service = new SiteInitializationService($root);
        $plan = new ArtifactPlan(
            'Example\\Compiler',
            1,
            'scaffold:example',
            GenerationUnitDisposition::Managed,
            str_repeat('b', 64),
            [new GeneratedArtifact('src/Example.php', "<?php // generated\n")],
        );
        $evaluation = $service->evaluate($plan);
        $invocation = $service->apply(new ArtifactApplyRequest($plan, $evaluation->planDigest, $evaluation->projectStateDigest));

        self::assertSame('applied', $invocation->result->outcome->value);
        $tester = $this->tester($root);
        $tester->execute(['--project-root=' . $root, '--strict', '--format=json']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $report = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($report['passed']);
        self::assertSame('OK', $report['summary']);
        self::assertSame([], $report['findings']);
        self::assertSame('strict', $report['mode']);
    }

    #[Test]
    public function managedUnitDriftRemainsBlockingThroughHandler(): void
    {
        $root = $this->fixture();
        $service = new SiteInitializationService($root);
        $plan = new ArtifactPlan(
            'Example\\Compiler',
            1,
            'scaffold:example',
            GenerationUnitDisposition::Managed,
            str_repeat('b', 64),
            [new GeneratedArtifact('src/Example.php', "<?php // generated\n")],
        );
        $evaluation = $service->evaluate($plan);
        $service->apply(new ArtifactApplyRequest($plan, $evaluation->planDigest, $evaluation->projectStateDigest));
        file_put_contents($root . '/src/Example.php', "<?php // substituted\n");

        $tester = $this->tester($root);
        $tester->execute(['--project-root=' . $root, '--strict', '--format=json']);

        self::assertSame(1, $tester->getExitCode());
        $report = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['passed']);
        self::assertSame('FAILED', $report['summary']);
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_column($report['findings'], 'id'));
    }

    private function tester(string $root): CliTester
    {
        $provider = new SiteServiceProvider(projectRoot: $root);
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

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_doctor_units_' . bin2hex(random_bytes(8));
        mkdir($root, 0o700, true);
        $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        $manifest = str_replace(str_repeat('a', 64), hash_file('sha256', $root . '/composer.lock'), $this->manifest());
        new SiteInitializationService($root)->initialize(new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest)));
        file_put_contents($root . '/composer.json', CanonicalJson::encode(['extra' => ['waaseyaa' => ['providers' => []]]]) . "\n");

        return $root;
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
