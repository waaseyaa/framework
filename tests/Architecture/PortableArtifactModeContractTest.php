<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\FieldAccessPreflightHandler;
use Waaseyaa\CLI\Security\DatabaseFieldAccessInventoryScanner;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * Behavioural guard for the framework's portable-artifact mode contract
 * (PROTOCOL302_BUNDLE_ENTRY / issue-site-verify-noexec-boundary).
 *
 * This does NOT enumerate `tempnam(` call sites — that was tried and
 * rejected: it would miss the identical defect produced by `fopen()`+
 * `rename()`, a stray umask, or any future mechanism, and it makes "a new
 * call site exists" the failure condition instead of "a portable artifact
 * has a non-portable mode". Every assertion below instead MATERIALIZES a
 * real artifact through its real production code path and inspects the
 * real inode mode `fileperms()` reports on disk.
 *
 * Two artifact families are exercised, matched to what the community-events
 * starter journey actually produces on this path:
 *
 * 1. PORTABLE — artifacts consumed by a process other than the one that
 *    generated them (a later boot, a different service, a bundler that
 *    reads the tree). These must carry mode 0644 or 0755, the exact
 *    contract {@see \Waaseyaa\SiteContract\Generation\GeneratedArtifact}
 *    already enforces at construction time for every generated project
 *    file. `.waaseyaa/field-access-preflight.json` is the confirmed
 *    PROTOCOL302 regression site (issue-site-verify-noexec-boundary): it
 *    is required at normal application boot
 *    ({@see \Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight})
 *    which is ordinarily a different process/invocation than the CLI run
 *    that wrote it.
 *
 * 2. INTENTIONALLY PRIVATE — control-plane state read only by the same
 *    process/user that wrote it, mid-transaction, and never part of the
 *    committed/bundled tree (it is excluded by the generator's own
 *    `.waaseyaa/.gitignore`: see
 *    {@see \Waaseyaa\SiteContract\Generation\SiteArtifactRenderer::controlIgnore()}).
 *    `.waaseyaa/site-init.transaction.json` is the concrete example: it is
 *    correctly 0600, and this test proves the portable-mode assertion does
 *    NOT flag it.
 *
 * Adding a new confirmed portable-artifact producer to this journey: add a
 * `#[Test]` method that materializes it for real and asserts its mode, the
 * same way the two below do. Adding a new confirmed intentionally-private
 * producer: capture its mode via a real hook (a fault injector, a callback
 * argument) mid-lifecycle if it does not survive to the end of a successful
 * run, and assert it is NOT 0644/0755 — never widen a private artifact to
 * satisfy this test.
 */
#[CoversNothing]
final class PortableArtifactModeContractTest extends TestCase
{
    private const int PORTABLE_MODE_644 = 0o644;
    private const int PORTABLE_MODE_755 = 0o755;

    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            new Filesystem()->remove($root);
        }
    }

    /**
     * CONFIRMED regression coverage (PROTOCOL302_BUNDLE_ENTRY): the
     * field-access preflight artifact is written into the project tree by
     * `field-access:preflight --write-artifact` and required at a LATER,
     * separate application boot. It must be portable.
     */
    #[Test]
    public function fieldAccessPreflightArtifactIsWrittenAtThePortableContractMode(): void
    {
        $root = $this->root();
        mkdir($root . '/.waaseyaa', 0o775, true);
        file_put_contents($root . '/VERSION', "0.1.0-test\n");
        file_put_contents($root . '/composer.lock', '{}');

        $database = DBALDatabase::createSqlite();
        $manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: new FieldDefinitionRegistry());
        $handler = new FieldAccessPreflightHandler(
            new DatabaseFieldAccessInventoryScanner($database, $manager),
            $manager,
            projectRoot: $root,
        );
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED, '', 'json'),
            new InputOption('write-artifact', null, InputOption::VALUE_NONE),
        ]);

        // The real production handler, writing a real file to real disk —
        // no reimplementation of its write path.
        self::assertSame(0, $handler->execute(new SymfonyCommandIO(
            new ArrayInput(['--write-artifact' => true], $definition),
            new BufferedOutput(),
        )));

        $target = $root . '/.waaseyaa/field-access-preflight.json';
        self::assertFileExists($target);
        $this->assertPortableMode($target, 'the field-access preflight artifact');
    }

    /**
     * Exercises the already-correct reference path — the generic generated
     * project tree `site:apply`/`site:init` publish — as a second, mechanism
     * -different regression lock, and proves the same portable-mode
     * assertion does NOT misfire on the transaction journal that is
     * deliberately 0600 and deliberately excluded from the bundled tree.
     */
    #[Test]
    public function generatedSiteArtifactsArePortableAndTheTransactionJournalStaysPrivate(): void
    {
        $root = $this->root();
        $journalPath = $root . '/.waaseyaa/site-init.transaction.json';
        $capturedJournalMode = null;

        // A real fault-injection hook (the same seam
        // SiteInitializationServiceTest.php uses for interruption tests) —
        // not a reimplementation of the journal writer — observes the real
        // on-disk journal mode mid-transaction, after it is written
        // (`before-replace` fires once each item's state is already flipped
        // to 'installing' and the journal has been persisted for that
        // state) and before the successful run's own cleanup removes it.
        $fault = static function (string $stage) use ($journalPath, &$capturedJournalMode): void {
            if ($stage === 'before-replace' && $capturedJournalMode === null && is_file($journalPath)) {
                $capturedJournalMode = fileperms($journalPath) & 0o777;
            }
        };

        $result = new SiteInitializationService($root, $fault)->initialize($this->generatedSite());
        self::assertFalse($result->cancelled, 'the generation fixture must publish cleanly for this guard to prove anything');
        self::assertNotEmpty($result->changedPaths, 'the generation fixture must actually publish artifacts for this guard to prove anything');

        self::assertNotNull($capturedJournalMode, 'the fault-injection hook never observed the transaction journal; the guard proves nothing without it');
        self::assertSame(
            0o600,
            $capturedJournalMode,
            'the site-init transaction journal is intentionally private control-plane state (never part of the bundled tree — see SiteArtifactRenderer::controlIgnore()) and must stay 0600',
        );

        $portableChecked = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($this->isIntentionallyPrivateControlPath($relative)) {
                continue;
            }
            $this->assertPortableMode($file->getPathname(), $relative);
            $portableChecked++;
        }
        self::assertGreaterThan(0, $portableChecked, 'the generation fixture produced no artifacts to check; the guard proves nothing');
    }

    /**
     * Isolates the checking mechanism itself from the two production-code
     * tests above: proves `assertPortableMode()` genuinely discriminates on
     * the real inode mode (0600 fails, 0644/0755 pass) rather than always
     * agreeing with whatever it is handed. The two tests above then prove
     * this mechanism is applied correctly — catching a real regression, and
     * not misfiring on a real intentionally-private artifact.
     */
    #[Test]
    public function thePortableModeAssertionGenuinelyDiscriminatesOnTheRealInodeMode(): void
    {
        $root = $this->root();
        $private = $root . '/private.bin';
        $portable = $root . '/portable.bin';
        file_put_contents($private, 'x');
        chmod($private, 0o600);
        file_put_contents($portable, 'x');
        chmod($portable, 0o644);

        $this->assertPortableMode($portable, 'the 0644 fixture');

        // expectException — not try/catch around fail() — so a no-op
        // assertPortableMode() cannot be mistaken for a successful reject.
        // AssertionFailedError from fail() would be swallowed by that catch.
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertPortableMode($private, 'the 0600 fixture');
    }

    private function assertPortableMode(string $path, string $description): void
    {
        $mode = fileperms($path);
        self::assertNotFalse($mode, sprintf('unable to stat %s', $description));
        self::assertContains(
            $mode & 0o777,
            [self::PORTABLE_MODE_644, self::PORTABLE_MODE_755],
            sprintf(
                '%s must be portable (mode 0644 or 0755); found %04o — a portable artifact left at a private-by-default mode (e.g. tempnam()\'s 0600) is bundled unreadable by whatever consumes it in a different process or user context',
                $description,
                $mode & 0o777,
            ),
        );
    }

    /**
     * The explicit, justified exception list for THIS journey. Every one of
     * these is control-plane state the generator's own `.waaseyaa/.gitignore`
     * (see SiteArtifactRenderer::controlIgnore()) already excludes from the
     * bundled/committed tree — they are read only by the same process/user
     * that wrote them, mid-transaction, and never survive a successful run
     * (or, for the lock file, never carry consumer-facing content). Widening
     * any of these to satisfy this test would be the exact security
     * regression the maintainer's binding instructions forbid.
     */
    private function isIntentionallyPrivateControlPath(string $relative): bool
    {
        return $relative === '.waaseyaa/site-init.transaction.json'
            || $relative === '.waaseyaa/site-init.lock'
            || str_starts_with($relative, '.waaseyaa/site-init-stage-')
            || str_starts_with($relative, '.waaseyaa/site-init-backup-')
            || str_contains($relative, '.waaseyaa/site-init.transaction.json.tmp-');
    }

    private function generatedSite(): GeneratedSite
    {
        $manifest = <<<'YAML'
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

        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_portable_artifact_mode_' . bin2hex(random_bytes(8));
        mkdir($root, 0o777, true);
        $this->roots[] = $root;

        return $root;
    }
}
