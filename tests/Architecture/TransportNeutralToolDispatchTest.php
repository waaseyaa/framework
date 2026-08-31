<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * ADR-022 D-9.3: the extracted contracts MUST NOT depend on HTTP request or
 * response classes, so an stdio adapter can consume them without dragging a
 * route registrar behind it.
 *
 * **Why this is proven out of process.** Inside the suite, Composer's
 * autoloader has already made every HTTP class loadable and has already loaded
 * many of them; an in-process assertion that "the dispatcher did not use HTTP"
 * would be a claim about source text dressed up as a runtime observation — the
 * circularity #2658's R-6 proof ran into when no in-process test could
 * distinguish two SAPI resolutions under `cli`. So the proof spawns a real PHP
 * process with **no Composer autoloader at all**: only a hand-rolled PSR-4 map
 * over `waaseyaa/ai-tools`, `waaseyaa/access`, and `waaseyaa/foundation`, plus a
 * tripwire that records any class requested outside those three. A complete
 * dispatch — capability scope, id allowlist, schema validation, reserve,
 * execute, finalize — then runs to success with HTTP absent from the process.
 *
 * The two seeded controls make the tripwire's silence meaningful: each
 * deliberately reaches for a forbidden class and asserts it is caught. A
 * detector that has only ever been observed not-firing is not evidence.
 */
#[CoversNothing]
final class TransportNeutralToolDispatchTest extends TestCase
{
    private const string PROBE = 'tests/Support/TransportNeutrality/no-http-dispatch-probe.php';

    /**
     * Namespaces the dispatch contracts must never reach, whether by import,
     * type hint, string, or attribute.
     */
    private const array FORBIDDEN_NAMESPACE_PREFIXES = [
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\',
        'Symfony\\Component\\Routing\\',
        'Psr\\Http\\',
        'Waaseyaa\\Routing\\',
        'Waaseyaa\\Api\\',
        'Waaseyaa\\Mcp\\',
        'Waaseyaa\\Foundation\\Http\\',
    ];

    /** The trees this contract governs. */
    private const array GOVERNED_TREES = [
        'packages/ai-tools/src/Dispatch',
        'packages/ai-tools/src/Registry',
    ];

    #[Test]
    public function a_full_dispatch_completes_in_a_process_where_http_is_unreachable(): void
    {
        $probe = $this->runProbe();

        self::assertNull($probe['error'], 'The dispatch must complete with only ai-tools, access, and foundation loadable.');
        self::assertSame([], $probe['foreign'], 'The dispatch path requested a class outside its permitted closure.');
        self::assertTrue($probe['ok']);
        self::assertSame('execution_succeeded', $probe['stage']);
        self::assertSame('probe-ok', $probe['envelope_text']);
        // Audit enforcement is part of what must work without HTTP: the
        // reserve/finalize pair is the D-5.B guarantee, observed here with the
        // HTTP package absent from the process entirely.
        self::assertSame(['reserve', 'finalize'], $probe['ledger_calls']);
    }

    /**
     * Seeded control 1: the tripwire fires on a Symfony HTTP class.
     *
     * Without this, "foreign was empty" could equally mean the tripwire never
     * works.
     */
    #[Test]
    public function the_probe_detects_a_symfony_http_class(): void
    {
        $probe = $this->runProbe('--seed-http-touch');

        self::assertContains('Symfony\\Component\\HttpFoundation\\Request', $probe['foreign']);
    }

    /** Seeded control 2: the tripwire fires on the HTTP MCP package itself. */
    #[Test]
    public function the_probe_detects_the_http_mcp_package(): void
    {
        $probe = $this->runProbe('--seed-mcp-touch');

        self::assertContains('Waaseyaa\\Mcp\\Bridge\\AgentToolRegistryBridge', $probe['foreign']);
    }

    /**
     * A static complement to the runtime proof. The probe answers "did this
     * dispatch touch HTTP"; this answers "could any code path in these trees",
     * including one no probe happens to exercise.
     */
    #[Test]
    public function no_governed_source_names_an_http_namespace(): void
    {
        $offenders = [];
        foreach ($this->governedFiles() as $file) {
            $code = $this->executableSource($file);
            foreach (self::FORBIDDEN_NAMESPACE_PREFIXES as $prefix) {
                // Match the namespace as written in source (single backslash)
                // and as written inside a double-quoted string (escaped).
                if (str_contains($code, $prefix) || str_contains($code, str_replace('\\', '\\\\', $prefix))) {
                    $offenders[] = basename($file) . ' → ' . $prefix;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /** The scanner is only evidence if it can find what it is looking for. */
    #[Test]
    public function the_static_scanner_detects_an_http_namespace_when_one_is_present(): void
    {
        $mcpEndpoint = $this->executableSource($this->root() . '/packages/mcp/src/McpEndpoint.php');

        $hits = array_values(array_filter(
            self::FORBIDDEN_NAMESPACE_PREFIXES,
            static fn(string $prefix): bool => str_contains($mcpEndpoint, $prefix),
        ));

        self::assertContains('Symfony\\Component\\HttpFoundation\\', $hits);

        // And the route registrar D-9.3 exists to keep out of the local plane.
        $routeProvider = $this->executableSource($this->root() . '/packages/mcp/src/McpRouteProvider.php');
        self::assertStringContainsString('Waaseyaa\\Routing\\', $routeProvider);
    }

    #[Test]
    public function the_governed_trees_actually_contain_the_extracted_contracts(): void
    {
        // A tree scan over an empty tree passes vacuously. Name the files the
        // contract is about, so a rename or a deletion fails here rather than
        // quietly retiring the guarantee.
        $names = array_map('basename', $this->governedFiles());
        sort($names);

        self::assertSame(
            [
                'AgentToolDispatcher.php',
                'AuditedToolDispatcher.php',
                'CapabilityScopedToolRegistry.php',
                'ToolDispatchOutcome.php',
                'ToolDispatcherInterface.php',
                'ToolIdAllowlistRegistry.php',
            ],
            $names,
        );
    }

    /**
     * The file's source with comments and docblocks removed.
     *
     * These classes discuss the HTTP MCP package at length in prose — the whole
     * point of the extraction is recorded there — and a naive `str_contains`
     * over the raw file would flag that documentation as a dependency. Scanning
     * tokens instead means the detector answers "does this code reach for HTTP",
     * which is the actual contract, and cannot be silenced by rewording a
     * comment either.
     */
    private function executableSource(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** @return list<string> */
    private function governedFiles(): array
    {
        $files = [];
        foreach (self::GOVERNED_TREES as $tree) {
            $dir = $this->root() . '/' . $tree;
            self::assertDirectoryExists($dir);
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array{ok: bool, stage: string, envelope_text: string, foreign: list<string>, error: ?string, tools: list<string>, ledger_calls: list<string>} */
    private function runProbe(string ...$flags): array
    {
        $root = $this->root();
        // symfony/process with an argv array, per #2491's subprocess contract:
        // it drains both pipes concurrently (a sequential drain deadlocks once
        // the child fills the ~64KB stderr buffer) and builds a portable
        // command line, so the probe runs the same on Windows — the failure
        // #2658's out-of-process proofs hit when a POSIX `NAME=value cmd`
        // prefix was handed to cmd.exe and the probe never ran at all.
        $process = new Process(
            array_merge([PHP_BINARY, $root . '/' . self::PROBE, $root], $flags),
            $root,
        );
        $process->setTimeout(60.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), 'Probe failed: ' . $process->getErrorOutput());

        $decoded = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
