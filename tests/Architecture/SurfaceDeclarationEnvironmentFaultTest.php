<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tooling\SurfaceDeclarations;
use Waaseyaa\Tooling\SurfaceScanner;

require_once __DIR__ . '/../../tools/lib/SurfaceScanner.php';
require_once __DIR__ . '/../../tools/lib/SurfaceDeclarations.php';
require_once __DIR__ . '/../../bin/lib/vendor-freshness.php';

/**
 * Regression proof for #2926 (surface-parity half): a declared FQCN whose
 * defining file exists on disk under a declared PSR-4 root but which the
 * autoloader cannot load is an ENVIRONMENT fault (stale vendor/), not an
 * "orphaned" declaration whose repair is a CHANGELOG deprecation directive.
 *
 * The real case: Waaseyaa\CLI\Io\StdinSource lives at
 * packages/cli/tests/Io/StdinSource.php, mapped only by the ROOT composer.json
 * autoload-dev — invisible to the src/-only AST walk and to the declaring
 * package's own PSR-4 prefixes — so a stale autoloader is the only thing that
 * ever decides whether it "loads".
 *
 * Fixture trees are never autoloaded (SurfaceScanner docblock), which makes a
 * `--root` fixture the exact harness for "exists on disk, not autoloadable".
 *
 * The classification is deliberately narrow: only a PSR-4 root the ROOT
 * autoloader is expected to honour counts — the root composer.json's
 * autoload/autoload-dev, or the `autoload` section of a package composer.lock
 * names. Composer never dumps a dependency's `autoload-dev`, so a symbol
 * defined only there can never load by design; calling that an environment
 * fault would send the contributor into a `composer install` loop that cannot
 * fix it (the #2926 review finding), so it stays a real orphan.
 */
#[CoversNothing]
final class SurfaceDeclarationEnvironmentFaultTest extends TestCase
{
    private string $repoRoot;
    private string $generator;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->generator = $this->repoRoot . '/bin/generate-surface-map';
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_surface_env_' . uniqid('', true);
        new Filesystem()->mkdir($this->tmpRoot . '/docs');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function a_symbol_defined_under_a_root_autoload_dev_psr4_root_is_an_environment_fault_not_an_orphan(): void
    {
        $this->writeGamma(extraEntries: [
            ['fqcn' => 'Waaseyaa\\Gamma\\Io\\StdinLikeInterface', 'disposition' => 'public'],
        ]);
        $this->writeRootComposer(['Waaseyaa\\Gamma\\Io\\' => 'packages/gamma/tests/Io/']);
        $this->writeInterface('packages/gamma/tests/Io/StdinLikeInterface.php', 'Waaseyaa\\Gamma\\Io', 'StdinLikeInterface');

        [$exit, $out] = $this->generate();

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $exit, "An on-disk-but-unloadable symbol must exit with the precondition code.\n{$out}");
        self::assertStringContainsString('environment', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\Io\\StdinLikeInterface', $out);
        self::assertStringContainsString('packages/gamma/tests/Io/StdinLikeInterface.php', $out);
        self::assertStringContainsString('composer install', $out);
        self::assertStringNotContainsString('orphaned:', $out, 'An environment fault must never be reported as an orphaned declaration.');
        self::assertStringNotContainsString('CHANGELOG', $out);
    }

    #[Test]
    public function a_symbol_defined_only_under_a_package_autoload_dev_psr4_root_stays_a_real_orphan(): void
    {
        // Composer never dumps a dependency's autoload-dev into the root
        // autoloader, so this symbol is unreachable BY DESIGN even after a
        // fresh `composer install`: a declaration defect, not an environment
        // fault. Even naming the package in composer.lock must not change that.
        $this->writeGamma(
            extraEntries: [['fqcn' => 'Waaseyaa\\Gamma\\Tests\\Support\\HelperInterface', 'disposition' => 'internal']],
            autoloadDev: ['Waaseyaa\\Gamma\\Tests\\' => 'tests/'],
        );
        $this->writeLock(['waaseyaa/gamma']);
        $this->writeInterface('packages/gamma/tests/Support/HelperInterface.php', 'Waaseyaa\\Gamma\\Tests\\Support', 'HelperInterface');

        [$exit, $out] = $this->generate();

        self::assertSame(1, $exit, "A dependency's autoload-dev root is never loadable; this must be the §4 defect exit, not the precondition code.\n{$out}");
        self::assertStringContainsString('orphaned', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\Tests\\Support\\HelperInterface', $out);
        self::assertStringNotContainsString('environment', $out);
        self::assertStringNotContainsString('composer install', $out);
    }

    #[Test]
    public function a_symbol_defined_under_a_locked_package_autoload_psr4_root_is_an_environment_fault_not_an_orphan(): void
    {
        // A non-src/ `autoload` root (invisible to the src/-only AST walk) of
        // a package composer.lock names: `composer install` dumps it, so an
        // autoloader that cannot load the file is stale — an environment fault.
        $this->writeGamma(
            extraEntries: [['fqcn' => 'Waaseyaa\\Gamma\\Testing\\FakeClockInterface', 'disposition' => 'internal']],
            autoload: ['Waaseyaa\\Gamma\\Testing\\' => 'testing/'],
        );
        $this->writeLock(['waaseyaa/gamma']);
        $this->writeInterface('packages/gamma/testing/FakeClockInterface.php', 'Waaseyaa\\Gamma\\Testing', 'FakeClockInterface');

        [$exit, $out] = $this->generate();

        self::assertSame(VENDOR_FRESHNESS_EXIT_CODE, $exit, $out);
        self::assertStringContainsString('environment', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\Testing\\FakeClockInterface', $out);
        self::assertStringContainsString('packages/gamma/composer.json autoload', $out);
        self::assertStringContainsString('composer install', $out);
        self::assertStringNotContainsString('orphaned:', $out);
    }

    #[Test]
    public function a_symbol_defined_under_an_unlocked_package_autoload_psr4_root_stays_a_real_orphan(): void
    {
        // Same tree as above, but composer.lock does not name waaseyaa/gamma:
        // the root autoloader will never dump that root, so `composer
        // install` cannot make the symbol load — a defect, not an environment
        // fault.
        $this->writeGamma(
            extraEntries: [['fqcn' => 'Waaseyaa\\Gamma\\Testing\\FakeClockInterface', 'disposition' => 'internal']],
            autoload: ['Waaseyaa\\Gamma\\Testing\\' => 'testing/'],
        );
        $this->writeLock(['waaseyaa/other']);
        $this->writeInterface('packages/gamma/testing/FakeClockInterface.php', 'Waaseyaa\\Gamma\\Testing', 'FakeClockInterface');

        [$exit, $out] = $this->generate();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('orphaned', $out);
        self::assertStringNotContainsString('environment', $out);
    }

    #[Test]
    public function a_symbol_with_no_defining_file_anywhere_stays_a_real_orphan(): void
    {
        // Control: the PSR-4 root is declared, but no file exists at the
        // resolved path — nothing on disk defines the symbol.
        $this->writeGamma(extraEntries: [
            ['fqcn' => 'Waaseyaa\\Gamma\\Io\\NeverWrittenInterface', 'disposition' => 'public'],
        ]);
        $this->writeRootComposer(['Waaseyaa\\Gamma\\Io\\' => 'packages/gamma/tests/Io/']);

        [$exit, $out] = $this->generate();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('orphaned', $out);
        self::assertStringContainsString('does not load', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\Io\\NeverWrittenInterface', $out);
        self::assertStringNotContainsString('environment', $out);
    }

    #[Test]
    public function a_file_at_the_resolved_path_that_defines_a_different_symbol_stays_a_real_orphan(): void
    {
        // Control: the file exists where PSR-4 says it should, but it does
        // not define the declared FQCN — a genuinely wrong declaration.
        $this->writeGamma(extraEntries: [
            ['fqcn' => 'Waaseyaa\\Gamma\\Io\\MisnamedInterface', 'disposition' => 'public'],
        ]);
        $this->writeRootComposer(['Waaseyaa\\Gamma\\Io\\' => 'packages/gamma/tests/Io/']);
        $this->writeInterface('packages/gamma/tests/Io/MisnamedInterface.php', 'Waaseyaa\\Gamma\\Io', 'SomethingElseInterface');

        [$exit, $out] = $this->generate();

        self::assertSame(1, $exit, $out);
        self::assertStringContainsString('orphaned', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\Io\\MisnamedInterface', $out);
        self::assertStringNotContainsString('environment', $out);
    }

    #[Test]
    public function locate_definition_resolves_the_real_stdin_source_case_through_the_root_autoload_dev_map(): void
    {
        $declarations = SurfaceDeclarations::load($this->repoRoot);
        $scanner = SurfaceScanner::scan($this->repoRoot);

        $located = $declarations->locateDefinition('Waaseyaa\\CLI\\Io\\StdinSource', $scanner);

        self::assertNotNull($located, 'StdinSource must resolve to its on-disk definition independently of the autoloader.');
        self::assertSame('packages/cli/tests/Io/StdinSource.php', $located['file']);
        self::assertSame('Waaseyaa\\CLI\\Io\\', $located['prefix']);
        self::assertSame('composer.json autoload-dev', $located['origin']);
        self::assertSame('interface', $located['shape']);
    }

    #[Test]
    public function locate_definition_ignores_a_real_package_autoload_dev_root_the_root_autoloader_never_honours(): void
    {
        // The exact #2926 review probe: this test class exists on disk under
        // packages/notification's autoload-dev, but the root composer.json
        // maps no `Waaseyaa\Notification\Tests\` prefix, so the ROOT
        // autoloader can never resolve it — and locateDefinition must not
        // call that an environment fault.
        //
        // The precondition is stated against the Composer ClassLoader's PSR-4
        // map, NOT class_exists(): PHPUnit includes test files directly, so
        // whenever packages/notification's tests ran earlier in this process
        // (a whole-configuration --filter run, or a shard that carries them)
        // the class IS declared even though the autoloader never mapped it.
        $declarations = SurfaceDeclarations::load($this->repoRoot);
        $scanner = SurfaceScanner::scan($this->repoRoot);
        $fqcn = 'Waaseyaa\\Notification\\Tests\\Unit\\DefaultNotifiableTest';
        $definition = $this->repoRoot . '/packages/notification/tests/Unit/DefaultNotifiableTest.php';

        self::assertFileExists($definition);

        $loader = require $this->repoRoot . '/vendor/autoload.php';
        self::assertInstanceOf(ClassLoader::class, $loader, 'vendor/autoload.php must return the root Composer ClassLoader.');
        self::assertArrayNotHasKey(
            'Waaseyaa\\Notification\\Tests\\',
            $loader->getPrefixesPsr4(),
            'Precondition of this proof: the root autoloader does not map the package autoload-dev prefix.',
        );
        self::assertFalse(
            $loader->findFile($fqcn),
            'Precondition of this proof: the root autoloader cannot resolve the FQCN to any file.',
        );

        // Declare the class deliberately so the proof below is demonstrably
        // independent of whether PHPUnit already included the file.
        if (!class_exists($fqcn, false)) {
            require_once $definition;
        }
        self::assertTrue(class_exists($fqcn, false));
        self::assertFalse($loader->findFile($fqcn), 'Loading the class must not change what the root autoloader maps.');

        self::assertNull($declarations->locateDefinition($fqcn, $scanner));
    }

    #[Test]
    public function locate_definition_returns_null_when_nothing_on_disk_defines_the_symbol(): void
    {
        $declarations = SurfaceDeclarations::load($this->repoRoot);
        $scanner = SurfaceScanner::scan($this->repoRoot);

        self::assertNull($declarations->locateDefinition('Waaseyaa\\CLI\\Io\\DoesNotExistAnywhere', $scanner));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, string>> $extraEntries
     * @param array<string, string> $autoloadDev
     * @param array<string, string> $autoload extra `autoload` PSR-4 roots beside the src/ one
     */
    private function writeGamma(array $extraEntries, array $autoloadDev = [], array $autoload = []): void
    {
        $fs = new Filesystem();
        $dir = "{$this->tmpRoot}/packages/gamma";
        $fs->mkdir("{$dir}/src");
        $composer = [
            'name' => 'waaseyaa/gamma',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Waaseyaa\\Gamma\\' => 'src/'] + $autoload],
        ];
        if ($autoloadDev !== []) {
            $composer['autoload-dev'] = ['psr-4' => $autoloadDev];
        }
        $fs->dumpFile("{$dir}/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->writeInterface('packages/gamma/src/GammaContractInterface.php', 'Waaseyaa\\Gamma', 'GammaContractInterface');
        $declaration = ['entries' => [
            ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'public'],
            ...$extraEntries,
        ]];
        $fs->dumpFile(
            "{$dir}/public-surface.php",
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($declaration, true) . ";\n",
        );
    }

    /** @param array<string, string> $autoloadDevPsr4 */
    private function writeRootComposer(array $autoloadDevPsr4): void
    {
        new Filesystem()->dumpFile("{$this->tmpRoot}/composer.json", json_encode([
            'name' => 'waaseyaa/framework',
            'autoload-dev' => ['psr-4' => $autoloadDevPsr4],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @param list<string> $packageNames */
    private function writeLock(array $packageNames): void
    {
        new Filesystem()->dumpFile("{$this->tmpRoot}/composer.lock", json_encode([
            'packages' => array_map(static fn(string $name): array => ['name' => $name, 'version' => 'dev-main'], $packageNames),
            'packages-dev' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function writeInterface(string $relativePath, string $namespace, string $name): void
    {
        new Filesystem()->dumpFile(
            "{$this->tmpRoot}/{$relativePath}",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\ninterface {$name}\n{\n}\n",
        );
    }

    /** @return array{int, string} */
    private function generate(): array
    {
        $process = new Process(
            [PHP_BINARY, $this->generator, '--root=' . $this->tmpRoot, '--stdout=php'],
            $this->repoRoot,
            null,
            null,
            120,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
