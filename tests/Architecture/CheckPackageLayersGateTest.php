<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fixture-driven proof that `bin/check-package-layers` flags BOTH classes of
 * file-level `use Waaseyaa\…` violation:
 *   - PL005: an upward layer edge (depends on a higher layer).
 *   - PL007: a same-or-lower-layer import not declared in composer.json require
 *            (the undeclared-dependency check added in Remediation Wave 4 W4-1/W4-3).
 *
 * The gate scans `<root>/packages`; both the scan root and the PL007 baseline path
 * are overridable via env (`WAASEYAA_LAYER_ROOT` / `WAASEYAA_LAYER_UNDECLARED_BASELINE`),
 * so we build a throwaway one-package tree per case and run the real script against it.
 */
#[CoversNothing]
final class CheckPackageLayersGateTest extends TestCase
{
    private string $repoRoot;
    private string $gate;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->gate = $this->repoRoot . '/bin/check-package-layers';
        self::assertFileExists($this->gate);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_layergate_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
    }

    #[Test]
    public function flags_undeclared_same_layer_use_edge_pl007(): void
    {
        // plugin (L0) imports queue (L0, same layer) but does not declare it in require.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['Waaseyaa\\Queue\\QueueInterface'],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an undeclared same-layer use-edge.\n{$out}");
        self::assertStringContainsString('PL007', $out);
        self::assertStringContainsString('plugin', $out);
        self::assertStringContainsString('queue', $out);
    }

    #[Test]
    public function flags_upward_use_edge_pl005(): void
    {
        // plugin (L0) imports node (L2) — an upward layer violation.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['Waaseyaa\\Node\\NodeInterface'],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an upward use-edge.\n{$out}");
        self::assertStringContainsString('PL005', $out);
        self::assertStringContainsString('plugin', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function baseline_suppresses_known_undeclared_edge(): void
    {
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['Waaseyaa\\Queue\\QueueInterface'],
        );

        $baselinePath = $this->tmpRoot . '/baseline.txt';
        file_put_contents($baselinePath, "# fixture baseline\nplugin queue  # src/Demo.php\n");

        [$exit, $out] = $this->runGate(baselinePath: $baselinePath);

        self::assertSame(0, $exit, "A baselined undeclared edge must not fail the gate.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function passes_when_dependency_is_declared(): void
    {
        // Declaring the dependency in require clears the PL007 finding.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5', 'waaseyaa/queue' => '^0.1'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['Waaseyaa\\Queue\\QueueInterface'],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(0, $exit, "Declaring the dependency should satisfy the gate.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function flags_quoted_string_literal_fqcn_pl008(): void
    {
        // plugin (L0) hard-codes a quoted 'Waaseyaa\\Node\\...' string literal (L2) —
        // PL008 sub-pattern (a), the original WP5 pattern.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: "private const FQCN = 'Waaseyaa\\\\Node\\\\Something';",
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on a quoted string-literal higher-layer FQCN.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('plugin', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function flags_inline_class_constant_fqcn_pl008(): void
    {
        // plugin (L0) references \Waaseyaa\Node\Something::class inline, with no `use`
        // import — PL008 sub-pattern (b), added WP7. Before the extension this shape
        // was invisible to both PL005 (no `use` statement to scan) and the original
        // PL008 regex (requires a leading quote character).
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: '\\Waaseyaa\\Node\\Something::class;',
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an inline ::class higher-layer FQCN.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('plugin', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function baseline_suppresses_known_inline_class_fqcn_pl008(): void
    {
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: '\\Waaseyaa\\Node\\Something::class;',
        );

        $stringLiteralBaselinePath = $this->tmpRoot . '/pl008-baseline.txt';
        file_put_contents(
            $stringLiteralBaselinePath,
            "# fixture PL008 baseline\nplugin/src/Demo.php  # fixture allowlist entry\n",
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true, stringLiteralBaselinePath: $stringLiteralBaselinePath);

        self::assertSame(0, $exit, "A baselined PL008 file must not fail the gate.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function does_not_flag_inline_class_fqcn_for_a_same_layer_dependency(): void
    {
        // plugin (L0) references \Waaseyaa\Queue\QueueInterface::class inline — queue is
        // also L0 (same layer), so this must NOT trip PL008 (which only fires when the
        // referenced package's layer is strictly greater than the containing package's).
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: '\\Waaseyaa\\Queue\\QueueInterface::class;',
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(0, $exit, "An inline ::class reference to a same-layer package must not fail PL008.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    /**
     * @param array<string, string> $require
     * @param list<string>          $useStatements FQCNs to `use` from the src file
     * @param string                $rawBody       Arbitrary raw text placed inside the class
     *                                              body — used to plant PL008 string-literal /
     *                                              inline `::class` fixtures. The gate scans raw
     *                                              file text with regexes, so this need not be
     *                                              syntactically valid PHP.
     */
    private function writeFixturePackage(
        string $short,
        array $require,
        string $relativeSrcFile,
        array $useStatements,
        string $rawBody = '',
    ): void {
        $pkgDir = $this->tmpRoot . '/packages/' . $short;
        $srcPath = $pkgDir . '/' . $relativeSrcFile;
        if (!is_dir(dirname($srcPath)) && !mkdir($concurrentDir = dirname($srcPath), 0o755, true) && !is_dir($concurrentDir)) {
            self::fail("Could not create fixture dir {$concurrentDir}");
        }

        file_put_contents($pkgDir . '/composer.json', json_encode([
            'name' => 'waaseyaa/' . $short,
            'type' => 'library',
            'require' => $require,
            'autoload' => ['psr-4' => ['Waaseyaa\\' . ucfirst($short) . '\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $uses = '';
        foreach ($useStatements as $fqcn) {
            $uses .= "use {$fqcn};\n";
        }
        file_put_contents(
            $srcPath,
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Waaseyaa\\" . ucfirst($short) . ";\n\n{$uses}\nfinal class Demo {\n{$rawBody}\n}\n",
        );
    }

    /**
     * @return array{0: int, 1: string} [exitCode, combinedOutput]
     */
    private function runGate(
        bool $emptyBaseline = false,
        ?string $baselinePath = null,
        ?string $stringLiteralBaselinePath = null,
    ): array {
        $baseline = $baselinePath ?? ($emptyBaseline ? '/dev/null' : '');
        // Default to /dev/null so fixture runs never fall through to the REAL repo's
        // tools/package-layers-string-literal-baseline.txt (which would silently
        // suppress fixture findings or, worse, pass because the fixture path never
        // matches a real baseline entry for unrelated reasons).
        $env = [
            'WAASEYAA_LAYER_ROOT' => $this->tmpRoot,
            'WAASEYAA_LAYER_UNDECLARED_BASELINE' => $baseline,
            'WAASEYAA_LAYER_STRING_LITERAL_BASELINE' => $stringLiteralBaselinePath ?? '/dev/null',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $this->gate],
            $descriptors,
            $pipes,
            $this->tmpRoot,
            $env,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, (string) $stdout . (string) $stderr];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
