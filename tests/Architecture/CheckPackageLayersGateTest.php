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
    public function flags_attachment_upward_use_edge_pl005(): void
    {
        // attachment is L2. The namespace index must not silently skip it when
        // an L1 package imports it without a declared dependency.
        $this->writeFixturePackage(
            short: 'entity',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['Waaseyaa\\Attachment\\AttachmentInterface'],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an upward attachment use-edge.\n{$out}");
        self::assertStringContainsString('PL005', $out);
        self::assertStringContainsString('attachment', $out);
    }

    #[Test]
    public function fails_before_scanning_when_namespace_map_drifts_from_layer_index(): void
    {
        mkdir($this->tmpRoot, 0o755, true);
        $mutatedGate = $this->tmpRoot . '/check-package-layers-with-map-drift';
        $source = (string) file_get_contents($this->gate);
        $mutated = str_replace("    'Attachment' => 'attachment',\n", '', $source);
        self::assertNotSame($source, $mutated, 'Fixture mutation must remove the attachment namespace map entry.');
        file_put_contents($mutatedGate, $mutated);

        [$exit, $out] = $this->runGate(emptyBaseline: true, gatePath: $mutatedGate);

        self::assertSame(1, $exit, "Namespace-map parity drift must fail before source scanning.\n{$out}");
        self::assertStringContainsString('PL009', $out);
        self::assertStringContainsString('attachment', $out);
    }

    #[Test]
    public function flags_inline_fqcn_above_layer_zero_pl008(): void
    {
        // bimaaji (L4) reaches upward to cli (L6) without a use import. PL008
        // must cover every layer, not only the historical Layer-0 incident.
        $this->writeFixturePackage(
            short: 'bimaaji',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: 'private const CLI_COMMAND = \\Waaseyaa\\CLI\\Command\\HandlerCommand::class;',
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an inline upward FQCN outside Layer 0.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('cli', $out);
    }

    #[Test]
    public function flags_new_same_layer_cycle_pl006(): void
    {
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5', 'waaseyaa/queue' => '^0.1'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
        );
        $this->writeFixturePackage(
            short: 'queue',
            require: ['php' => '>=8.5', 'waaseyaa/plugin' => '^0.1'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true, emptyCycleBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on a new same-layer cycle.\n{$out}");
        self::assertStringContainsString('PL006', $out);
        self::assertStringContainsString('plugin <-> queue', $out);
    }

    #[Test]
    public function accepts_an_explicitly_baselined_same_layer_cycle_pl006(): void
    {
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5', 'waaseyaa/queue' => '^0.1'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
        );
        $this->writeFixturePackage(
            short: 'queue',
            require: ['php' => '>=8.5', 'waaseyaa/plugin' => '^0.1'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
        );
        $cycleBaseline = $this->tmpRoot . '/cycle-baseline.txt';
        file_put_contents($cycleBaseline, "plugin queue  # fixture accepted cycle\n");

        [$exit, $out] = $this->runGate(emptyBaseline: true, cycleBaselinePath: $cycleBaseline);

        self::assertSame(0, $exit, "An explicitly baselined same-layer cycle must remain accepted.\n{$out}");
        self::assertStringContainsString('Accepted baseline', $out);
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
    public function flags_leading_backslash_use_import_pl005_outside_layer_zero(): void
    {
        // entity (L1) imports api (L4) via `use \Waaseyaa\Api\...;` — the optional
        // leading backslash is valid PHP with identical semantics, and previously
        // evaded PL005's regex in EVERY layer (WP7 adversarial-review finding).
        // A non-Layer-0 package is deliberate: a plain use import remains PL005's
        // responsibility even though PL008 now scans inline FQCNs in every layer.
        $this->writeFixturePackage(
            short: 'entity',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: ['\\Waaseyaa\\Api\\JsonApiController'],
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on a leading-backslash upward use-import.\n{$out}");
        self::assertStringContainsString('PL005', $out);
        self::assertStringContainsString('entity', $out);
        self::assertStringContainsString('api', $out);
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

    #[Test]
    public function flags_static_method_access_on_inline_fq_name_pl008(): void
    {
        // Adversarial-review MAJOR-1: \Waaseyaa\Node\Something::make() is REAL runtime
        // coupling (autoloads the class, unlike ::class) yet has no `use` import for
        // PL005 and no `::class` suffix for the original sub-pattern (b) regex.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                public function boot(): void { \Waaseyaa\Node\Something::make(); }
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an inline fully-qualified static-method access.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function flags_leading_backslash_quoted_fqcn_pl008(): void
    {
        // Adversarial-review MAJOR-2: '\Waaseyaa\Node\Something' — the leading
        // backslash inside the quotes evaded the original quote-anchored regex.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                private const FQCN = '\Waaseyaa\Node\Something';
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on a leading-backslash quoted higher-layer FQCN.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function flags_single_backslash_quoted_fqcn_pl008(): void
    {
        // Adversarial-review MAJOR-2 (related): 'Waaseyaa\Node\Something' with SINGLE
        // backslash separators is valid PHP in a single-quoted string ('\N' is a literal
        // backslash + N) and evaded the original regex, which required raw double
        // backslashes as separators.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                private const FQCN = 'Waaseyaa\Node\Something';
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on a single-backslash quoted higher-layer FQCN.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function flags_inline_fq_instantiation_pl008(): void
    {
        // new \Waaseyaa\Node\Something() — fully-qualified instantiation, no `use`
        // import, no ::class suffix: invisible to both PL005 and the original PL008.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                public function make(): object { return new \Waaseyaa\Node\Something(); }
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(1, $exit, "Gate must fail on an inline fully-qualified instantiation.\n{$out}");
        self::assertStringContainsString('PL008', $out);
        self::assertStringContainsString('node', $out);
    }

    #[Test]
    public function does_not_flag_fqcn_in_trailing_line_comment(): void
    {
        // Adversarial-review MAJOR-3: the original line-based comment stripping only
        // skipped WHOLE comment lines, so a trailing same-line // comment mentioning a
        // higher-layer FQCN failed the gate on legitimate code.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                private int $x = 1; // see \Waaseyaa\Node\Something::class for the L2 counterpart
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(0, $exit, "An FQCN inside a trailing same-line // comment must not fail PL008.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function does_not_flag_fqcn_in_trailing_block_comment(): void
    {
        // Adversarial-review MAJOR-3 (block-comment variant).
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                private int $x = 1; /* \Waaseyaa\Node\Something::class */
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(0, $exit, "An FQCN inside a trailing same-line /* */ comment must not fail PL008.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    #[Test]
    public function does_not_flag_fqcn_in_docblock_see_reference(): void
    {
        // Docblock @see references are documentation, not coupling — pin that they pass.
        $this->writeFixturePackage(
            short: 'plugin',
            require: ['php' => '>=8.5'],
            relativeSrcFile: 'src/Demo.php',
            useStatements: [],
            rawBody: <<<'BODY'
                /** @see \Waaseyaa\Node\Something::class */
                private int $x = 1;
                BODY,
        );

        [$exit, $out] = $this->runGate(emptyBaseline: true);

        self::assertSame(0, $exit, "An FQCN inside a docblock @see must not fail PL008.\n{$out}");
        self::assertStringContainsString('OK', $out);
    }

    /**
     * @param array<string, string> $require
     * @param list<string>          $useStatements FQCNs to `use` from the src file
     * @param string                $rawBody       Arbitrary raw text placed inside the class
     *                                              body — used to plant PL008 string-scalar /
     *                                              inline fully-qualified-name fixtures. The
     *                                              gate tokenizes files lexically
     *                                              (token_get_all), which does not require a
     *                                              full parse, so this need only be lexically
     *                                              valid PHP.
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
        bool $emptyCycleBaseline = false,
        ?string $cycleBaselinePath = null,
        ?string $gatePath = null,
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
            'WAASEYAA_LAYER_CYCLE_BASELINE' => $cycleBaselinePath ?? ($emptyCycleBaseline ? '/dev/null' : ''),
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $gatePath ?? $this->gate],
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
