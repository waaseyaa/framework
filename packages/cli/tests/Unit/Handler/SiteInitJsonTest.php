<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Handler\SiteInitHandler;
use Waaseyaa\CLI\Provider\SiteServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\CanonicalJson;

#[CoversClass(SiteInitHandler::class)]
#[CoversClass(SiteServiceProvider::class)]
final class SiteInitJsonTest extends TestCase
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
    public function jsonMissingAnswersReturnsOneValidErrorObjectWithoutControlWrites(): void
    {
        $root = $this->root();
        $tester = $this->tester($root);

        $tester->execute(['--project-root=' . $root, '--json']);

        self::assertSame(2, $tester->getExitCode());
        $stdout = trim($tester->getStdout());
        self::assertSame(0, substr_count($stdout, "\n"));
        $document = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($document['evaluation']);
        self::assertNull($document['result']);
        self::assertSame([], $document['receipts']);
        self::assertNotEmpty($document['errors']);
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function jsonPublicationWithoutYesReturnsOneValidErrorObjectWithoutControlWrites(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--json']);

        self::assertSame(2, $tester->getExitCode());
        $stdout = trim($tester->getStdout());
        self::assertSame(0, substr_count($stdout, "\n"));
        $document = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($document['evaluation']);
        self::assertNull($document['result']);
        self::assertSame([], $document['receipts']);
        self::assertNotEmpty($document['errors']);
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function dryRunJsonEmitsEvaluationAndPlannedResultWithoutControlWrites(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run', '--json']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $document = json_decode(trim($tester->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document['evaluation']);
        self::assertSame('waaseyaa.artifact_plan', $document['evaluation']['plan']['schema']);
        self::assertSame(1, $document['evaluation']['plan']['version']);
        self::assertSame(hash('sha256', CanonicalJson::encode($document['evaluation']['plan']) . "\n"), $document['evaluation']['plan_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $document['evaluation']['project_state_digest']);
        self::assertSame('planned', $document['result']['outcome']);
        self::assertSame([], $document['result']['changed']);
        self::assertSame([], $document['receipts']);
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function yesJsonEmitsAppliedThenNoChangesReceipts(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->manifest());
        $first = $this->tester($root);

        $first->execute(["--answers={$answers}", "--project-root={$root}", '--yes', '--json']);

        self::assertSame(0, $first->getExitCode(), $first->getStderr());
        $applied = json_decode(trim($first->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('applied', $applied['result']['outcome']);
        self::assertContains('.waaseyaa/.gitignore', $applied['result']['changed']);
        self::assertContains('.waaseyaa/generated.json', $applied['result']['changed']);
        self::assertCount(1, $applied['receipts']);
        self::assertSame('applied', $applied['receipts'][0]['outcome']);
        self::assertSame(SiteInitializationService::CONTRACT_VERSION, $applied['receipts'][0]['authority_version']);
        self::assertSame($applied['result']['plan_digest'], $applied['receipts'][0]['plan_digest']);
        self::assertSame($applied['result']['changed'], $applied['receipts'][0]['domain_payload']['changed']);
        self::assertMatchesRegularExpression('/^rcpt-[a-f0-9]{32}$/D', $applied['receipts'][0]['receipt_id']);
        self::assertSame([], $this->receiptFiles($root));

        $second = $this->tester($root);
        $second->execute(["--answers={$answers}", "--project-root={$root}", '--yes', '--json']);

        self::assertSame(0, $second->getExitCode(), $second->getStderr());
        $noChanges = json_decode(trim($second->getStdout()), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('no_changes', $noChanges['result']['outcome']);
        self::assertCount(1, $noChanges['receipts']);
        self::assertSame('no_op', $noChanges['receipts'][0]['outcome']);
        self::assertSame($noChanges['result']['plan_digest'], $noChanges['receipts'][0]['plan_digest']);
        self::assertNotSame($applied['receipts'][0]['receipt_id'], $noChanges['receipts'][0]['receipt_id']);
        self::assertSame([], $this->receiptFiles($root));
    }

    private function tester(string $root): CliTester
    {
        $provider = new SiteServiceProvider(projectRoot: $root);
        $command = iterator_to_array($provider->consoleCommands())[0];
        $handler = new SiteInitHandler($root);
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly SiteInitHandler $handler) {}
            public function get(string $id): mixed
            {
                return $id === SiteInitHandler::class ? $this->handler : throw new \RuntimeException('Unexpected service.');
            }
            public function has(string $id): bool
            {
                return $id === SiteInitHandler::class;
            }
        };

        return CliTester::for($command, $container);
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_site_init_json_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->roots[] = $root;

        return $root;
    }

    /** @return list<string> */
    private function receiptFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains(strtolower($file->getFilename()), 'receipt')) {
                $files[] = $file->getFilename();
            }
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function manifest(): string
    {
        return <<<'YAML'
schema: waaseyaa.site
version: 1
generator_version: 1
application:
  id: example
  name: Example
  canonical_origin: {config_key: APP_ORIGIN}
framework:
  revision_policy: exact-lock
  observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
content_types:
  - {id: page, canonical_route: '/{slug}'}
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
