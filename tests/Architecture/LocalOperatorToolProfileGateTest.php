<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Testing\Filesystem\TemporaryDirectory;

/**
 * The seeded positive control for `bin/check-local-operator-tool-profile`
 * (ADR-022 D-7.3; #2658's added acceptance criterion).
 *
 * **A membership gate never observed failing proves nothing.** The gate's
 * whole value is that adding, removing, or renaming a tool the default profile
 * would admit fails CI until the roster is deliberately updated — and the only
 * way to know it still does that is to make it happen. So every test below
 * seeds a change into a disposable copy of the tree and asserts the gate goes
 * red, with a green control first so a gate that failed for an unrelated
 * reason cannot masquerade as working.
 *
 * The copy is disposable and lives under the system temp directory; the
 * repository tree is never written to.
 */
#[CoversNothing]
final class LocalOperatorToolProfileGateTest extends TestCase
{
    private string $root;
    private TemporaryDirectory $temporary;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        // #2491's sanctioned owned-tree helper: it removes only the tree it
        // created, and it unlinks symlinks rather than descending them —
        // which matters here, because the sandbox's sibling packages are
        // symlinks INTO the repository.
        $this->temporary = new TemporaryDirectory('waaseyaa-local-operator-gate-');
        $this->sandbox = $this->temporary->path();

        mkdir($this->sandbox . '/packages/ai-agent', recursive: true);
        mkdir($this->sandbox . '/support', recursive: true);

        // The package that is seeded is copied for real; every other package's
        // src/ is symlinked, so the sandbox sees the complete live tool set
        // without duplicating it and without any chance of writing into the
        // repository.
        $this->copyTree($this->root . '/packages/ai-agent/src', $this->sandbox . '/packages/ai-agent/src');
        foreach (new \DirectoryIterator($this->root . '/packages') as $package) {
            if ($package->isDot() || !$package->isDir() || $package->getFilename() === 'ai-agent') {
                continue;
            }
            $source = $package->getPathname() . '/src';
            if (!is_dir($source)) {
                continue;
            }
            mkdir($this->sandbox . '/packages/' . $package->getFilename(), recursive: true);
            symlink($source, $this->sandbox . '/packages/' . $package->getFilename() . '/src');
        }

        copy(
            $this->root . '/support/local-operator-tool-profile-roster.json',
            $this->sandbox . '/support/local-operator-tool-profile-roster.json',
        );
    }

    protected function tearDown(): void
    {
        $this->temporary->remove();
    }

    /**
     * Green control. The sandbox reproduces the repository's own result, so a
     * red result in the tests below is caused by the seeding and nothing else.
     */
    #[Test]
    public function the_gate_passes_on_an_unmodified_copy_of_the_tree(): void
    {
        [$exitCode, $output] = $this->runGate();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('roster passed', $output);
        self::assertStringContainsString('"default-profile":3', $output);
    }

    /**
     * The exact hazard D-7.1 exists for: a new tool declaring the capability
     * the default profile grants. Under a capability grant it would join the
     * default silently; under the tool-ID allowlist it is recorded as
     * `capability-admissible` and the gate refuses until a human looks.
     */
    #[Test]
    public function the_gate_fails_when_a_new_tool_declares_the_granted_capability(): void
    {
        $this->seedTool('SeededProbeTool', 'bimaaji_seeded_probe', 'bimaaji.read');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, 'The gate MUST fail on a new bimaaji.read tool. Output: ' . $output);
        self::assertStringContainsString('bimaaji_seeded_probe', $output);
        self::assertStringContainsString('capability-admissible', $output);
        self::assertStringContainsString('--write-roster', $output, 'the failure must name its own repair command');
    }

    /**
     * The scan must not be defeated by formatting.
     *
     * `new AgentTool (` — one space before the paren — is valid PHP and lints
     * clean. The gate once prefiltered files with
     * `str_contains($contents, 'AgentTool(')`, so that single space made the
     * whole file invisible and the gate reported green with an unrostered
     * `bimaaji.read` tool present. The prefilter is now the bare identifier,
     * a strict superset of what the token walk can match.
     *
     * This matters more than its size: the gate's entire value rests on its
     * scan being complete. An attribute-only scan would already have missed
     * `content.search`, which `AiToolsServiceProvider` registers
     * programmatically — a prefilter a space defeats reopens the same hole.
     */
    #[Test]
    public function the_gate_fails_when_a_tool_is_declared_with_a_space_before_the_paren(): void
    {
        $this->seedSpacedConstructorTool('spaced_probe', 'bimaaji.read');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(
            1,
            $exitCode,
            'A space before the paren must not hide a declaration from the gate. Output: ' . $output,
        );
        self::assertStringContainsString('spaced_probe', $output);
        self::assertStringContainsString('capability-admissible', $output);
    }

    /** The same blind spot, on the attribute form. */
    #[Test]
    public function the_gate_fails_when_an_attribute_is_declared_with_unusual_spacing(): void
    {
        file_put_contents(
            $this->sandbox . '/packages/ai-agent/src/Tool/Bimaaji/SpacedAttributeProbeTool.php',
            <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

                use Waaseyaa\AI\Tools\Attribute\AsAgentTool;

                #[ AsAgentTool ( name: 'spaced_attribute_probe', capability: 'bimaaji.read' ) ]
                final class SpacedAttributeProbeTool
                {
                }
                PHP,
        );

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('spaced_attribute_probe', $output);
    }

    /** Any new tool at all has to pass through a deliberate roster update. */
    #[Test]
    public function the_gate_fails_when_a_new_withheld_tool_appears(): void
    {
        $this->seedTool('SeededMutationTool', 'bimaaji_seeded_mutation', 'bimaaji.mutate');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('bimaaji_seeded_mutation', $output);
    }

    /** Removing an allowlisted tool silently empties the default profile. */
    #[Test]
    public function the_gate_fails_when_an_allowlisted_tool_is_removed(): void
    {
        unlink($this->sandbox . '/packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('bimaaji_search_specs', $output);
        self::assertStringContainsString('renamed or removed', $output);
    }

    #[Test]
    public function the_gate_fails_when_an_allowlisted_tool_is_renamed(): void
    {
        $this->renameSectionTool();

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('bimaaji_introspect_section', $output);
        self::assertStringContainsString('renamed or removed', $output);
    }

    /**
     * The property that makes the gate more than a changelog: regenerating the
     * roster does NOT silence a rename. The allowlist-versus-live cross-checks
     * are computed from `LocalOperatorToolProfile::DEFAULT_TOOL_IDS`, not from
     * the roster, so a reflexive `--write-roster` still leaves the gate red
     * until the allowlist itself is deliberately edited.
     */
    #[Test]
    public function regenerating_the_roster_does_not_silence_a_rename(): void
    {
        $this->renameSectionTool();

        [$writeExit, $writeOutput] = $this->runGate(['--write-roster']);
        self::assertSame(0, $writeExit, $writeOutput);

        [$exitCode, $output] = $this->runGate();

        self::assertSame(1, $exitCode, 'A lazy roster regenerate must not silence a widened/narrowed default.');
        self::assertStringContainsString('differ from LocalOperatorToolProfile::DEFAULT_TOOL_IDS', $output);
    }

    /** A missing roster is a hard infrastructure failure, not a pass. */
    #[Test]
    public function the_gate_fails_when_the_roster_is_missing(): void
    {
        unlink($this->sandbox . '/support/local-operator-tool-profile-roster.json');

        [$exitCode, $output] = $this->runGate();

        self::assertSame(2, $exitCode, $output);
        self::assertStringContainsString('missing', $output);
    }

    private function renameSectionTool(): void
    {
        $path = $this->sandbox . '/packages/ai-agent/src/Tool/Bimaaji/IntrospectSectionTool.php';
        file_put_contents($path, str_replace(
            "name: 'bimaaji_introspect_section'",
            "name: 'bimaaji_introspect_section_v2'",
            (string) file_get_contents($path),
        ));
    }

    /**
     * Seed a programmatic `new AgentTool (...)` registration with a space
     * before the paren — valid PHP that a paren-bearing prefilter cannot see.
     */
    private function seedSpacedConstructorTool(string $toolId, string $capability): void
    {
        // A nowdoc so nothing here is interpolated by THIS file; the two
        // placeholders are substituted with sprintf.
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

            use Waaseyaa\AI\Tools\AgentTool;
            use Waaseyaa\AI\Tools\AgentToolInterface;
            use Waaseyaa\AI\Tools\ToolRegistryInterface;

            final class SpacedConstructorProbe
            {
                /** @param array<string, mixed> $schema */
                public function register(ToolRegistryInterface $registry, AgentToolInterface $impl, array $schema): void
                {
                    $registry->register(new AgentTool (
                        name: '%s',
                        capability: '%s',
                        destructive: false,
                        dryRunSupported: false,
                        category: 'bimaaji',
                        inputSchema: $schema,
                        impl: $impl,
                    ));
                }
            }
            PHP;

        file_put_contents(
            $this->sandbox . '/packages/ai-agent/src/Tool/Bimaaji/SpacedConstructorProbe.php',
            sprintf($template, $toolId, $capability),
        );
    }

    private function seedTool(string $className, string $toolId, string $capability): void
    {
        file_put_contents(
            $this->sandbox . '/packages/ai-agent/src/Tool/Bimaaji/' . $className . '.php',
            <<<PHP
                <?php

                declare(strict_types=1);

                namespace Waaseyaa\\AI\\Agent\\Tool\\Bimaaji;

                use Waaseyaa\\AI\\Tools\\Attribute\\AsAgentTool;

                #[AsAgentTool(
                    name: '{$toolId}',
                    capability: '{$capability}',
                    destructive: false,
                    dryRunSupported: true,
                    category: 'bimaaji',
                )]
                final class {$className}
                {
                }
                PHP,
        );
    }

    /**
     * @param list<string> $extraArguments
     * @return array{0: int, 1: string}
     */
    private function runGate(array $extraArguments = []): array
    {
        $command = sprintf(
            '%s %s --root=%s --roster=%s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/bin/check-local-operator-tool-profile'),
            escapeshellarg($this->sandbox),
            escapeshellarg($this->sandbox . '/support/local-operator-tool-profile-roster.json'),
        );
        foreach ($extraArguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    private function copyTree(string $source, string $destination): void
    {
        mkdir($destination, recursive: true);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $target = $destination . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, recursive: true);
                continue;
            }
            copy($item->getPathname(), $target);
        }
    }
}
