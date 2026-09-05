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

/**
 * Activation retains fail-closed CLI behavior: feature negotiation now
 * admits the installed blueprint compiler, but its execution requires
 * approval in both text and JSON modes before any generated state.
 */
#[CoversClass(SiteInitHandler::class)]
final class SiteInitBlueprintNegotiationTest extends TestCase
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
    public function dryRunTextRefusesGen011BeforeAnyWrite(): void
    {
        $root = $this->root();
        $answers = $this->writeAnswers($root);
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('GEN011_UNAUTHORIZED_SET_DELTA', $tester->getStderr());
        self::assertStringContainsString('matching approved decision receipt', $tester->getStderr());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function applyYesTextRefusesGen011BeforeAnyWrite(): void
    {
        $root = $this->root();
        $answers = $this->writeAnswers($root);
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--yes']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('GEN011_UNAUTHORIZED_SET_DELTA', $tester->getStderr());
        self::assertStringContainsString('matching approved decision receipt', $tester->getStderr());
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');
    }

    #[Test]
    public function dryRunJsonAndApplyJsonEmitIdenticalCodedEnvelopesBeforeAnyWrite(): void
    {
        $root = $this->root();
        $answers = $this->writeAnswers($root);

        $dryRunTester = $this->tester($root);
        $dryRunTester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run', '--json']);
        self::assertSame(2, $dryRunTester->getExitCode());
        $dryRunDocument = json_decode(trim($dryRunTester->getStdout()), true, flags: \JSON_THROW_ON_ERROR);

        $applyTester = $this->tester($root);
        $applyTester->execute(["--answers={$answers}", "--project-root={$root}", '--json', '--yes']);
        self::assertSame(2, $applyTester->getExitCode());
        $applyDocument = json_decode(trim($applyTester->getStdout()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame($dryRunDocument, $applyDocument);
        self::assertNull($dryRunDocument['evaluation']);
        self::assertNull($dryRunDocument['result']);
        self::assertSame([], $dryRunDocument['receipts']);
        self::assertCount(1, $dryRunDocument['errors']);
        self::assertSame('GEN011_UNAUTHORIZED_SET_DELTA', $dryRunDocument['errors'][0]['code']);
        self::assertArrayNotHasKey('pointer', $dryRunDocument['errors'][0]);
        self::assertStringContainsString('matching approved decision receipt', $dryRunDocument['errors'][0]['message']);
        self::assertDirectoryDoesNotExist($root . '/.waaseyaa');

        self::assertSame(
            json_encode($dryRunDocument, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
            (string) file_get_contents(
                \dirname(__DIR__, 2) . '/Fixtures/SiteInit/blueprint-approval-refused.json',
            ),
        );
    }

    #[Test]
    public function blueprintFreeAnswersKeepTheirExistingBehaviour(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->blueprintFreeManifest());
        $tester = $this->tester($root);

        $tester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run', '--json']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        $document = json_decode(trim($tester->getStdout()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document['evaluation']);
        self::assertArrayNotHasKey('errors', $document);
    }

    /**
     * R2-3: `SiteInitHandler`'s new `catch (GenerationRefusalException)` for
     * negotiation must not widen the envelope for an EXISTING engine
     * refusal that also happens to be a `GenerationRefusalException`
     * (it extends `\RuntimeException`, same as negotiation's). Deleting
     * `.waaseyaa/site.yaml` after a blueprint-free apply leaves the
     * ownership metadata (`.waaseyaa/generated.json`) without its manifest
     * authority, so `SiteInitializationService::prepareUnitPlan()` refuses
     * `GEN010_UNIT_PATH_CONFLICT` sourced `'generation'`, not `'site:init'`
     * — the handler must route that through the pre-existing uncoded
     * `writeError()` path, exactly as it did before this handler learned
     * about negotiation, not through `writeCodedError()`.
     */
    #[Test]
    public function anEngineRefusalOnAPreviouslyBlueprintFreeProjectKeepsTheUncodedEnvelopeShape(): void
    {
        $root = $this->root();
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->blueprintFreeManifest());

        $applyTester = $this->tester($root);
        $applyTester->execute(["--answers={$answers}", "--project-root={$root}", '--json', '--yes']);
        self::assertSame(0, $applyTester->getExitCode(), $applyTester->getStderr());
        self::assertFileExists($root . '/.waaseyaa/site.yaml');

        unlink($root . '/.waaseyaa/site.yaml');

        $dryRunTester = $this->tester($root);
        $dryRunTester->execute(["--answers={$answers}", "--project-root={$root}", '--dry-run', '--json']);

        self::assertSame(2, $dryRunTester->getExitCode());
        $document = json_decode(trim($dryRunTester->getStdout()), true, flags: \JSON_THROW_ON_ERROR);
        self::assertNull($document['evaluation']);
        self::assertNull($document['result']);
        self::assertSame([], $document['receipts']);
        self::assertCount(1, $document['errors']);
        self::assertSame(['message'], array_keys($document['errors'][0]));
        self::assertStringContainsString('GEN010_UNIT_PATH_CONFLICT', $document['errors'][0]['message']);
        self::assertStringStartsWith('generation GEN010_UNIT_PATH_CONFLICT:', $document['errors'][0]['message']);
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
        $root = sys_get_temp_dir() . '/waaseyaa_site_init_blueprint_negotiation_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->roots[] = $root;

        return $root;
    }

    private function writeAnswers(string $root): string
    {
        $answers = $root . '/answers.yaml';
        file_put_contents($answers, $this->blueprintManifest());

        return $answers;
    }

    private function blueprintManifest(): string
    {
        return (string) file_get_contents(
            \dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml',
        );
    }

    private function blueprintFreeManifest(): string
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
            verification:
              command: bin/maintenance/site-verify
            YAML;
    }
}
