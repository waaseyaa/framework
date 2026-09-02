<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tests\Support\ComposerProjectFixture;

/**
 * A conformant local stdio MCP server, spawned for real over real OS pipes
 * (ADR-022 D-9.2, #2659).
 *
 * This is the transport's own proof, distinct from and complementary to the
 * two levels below it:
 *
 *  - `StdioMcpServerTest` (unit) proves the JSON-RPC wire behaviour in
 *    isolation, with a stub catalogue and `php://memory` streams.
 *  - `McpServeCommandTest` (unit) proves the wiring — local-operator
 *    construction, allowlist narrowing, audit reserve/finalize — with fake
 *    collaborators.
 *
 * Only THIS test proves what a client actually experiences: a real `php`
 * process, started the way an MCP host starts one, fed real bytes down a
 * real pipe, answering over a real pipe, backed by a real SQLite-audited
 * kernel boot. In particular it is the only place that can catch "a stray
 * echo corrupts the stream" — a warning some future dependency prints to
 * STDOUT, a boot banner, a deprecation notice — because those only exist
 * outside this process's own source, in the full dependency graph a real
 * `composer`-shaped install pulls in.
 */
#[CoversNothing]
final class StdioMcpConformanceTest extends TestCase
{
    private string $repoRoot;
    private string $projectRoot;

    /** @var resource|null */
    private $process;

    /** @var array{0: resource, 1: resource, 2: resource}|null */
    private ?array $pipes = null;

    private string $stderrBuffer = '';

    protected function setUp(): void
    {
        $this->repoRoot = (string) realpath(__DIR__ . '/../../..');
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_mcp_stdio_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        symlink($this->repoRoot . '/packages', $this->projectRoot . '/packages');

        ComposerProjectFixture::installMetadata($this->repoRoot, $this->projectRoot);

        file_put_contents($this->projectRoot . '/composer.json', json_encode([
            'name' => 'waaseyaa/mcp-stdio-conformance-fixture',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        file_put_contents($this->projectRoot . '/config/entity-types.php', "<?php\nreturn [];\n");
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->configFile('local'));
    }

    protected function tearDown(): void
    {
        $this->stopServer();

        if ($this->projectRoot !== '' && is_dir($this->projectRoot)) {
            new Filesystem()->remove($this->projectRoot);
        }
    }

    #[Test]
    public function initialize_tools_list_and_tools_call_round_trip_over_real_pipes_with_nothing_but_json_rpc_on_stdout(): void
    {
        $this->installAndBoot();
        $this->startServer();

        $init = $this->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2026-07-28',
            'capabilities' => [],
            'clientInfo' => ['name' => 'conformance-probe', 'version' => '0.0.1'],
        ]]);
        self::assertSame(1, $init['id']);
        self::assertSame('2026-07-28', $init['result']['protocolVersion']);
        self::assertSame('waaseyaa', $init['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $init['result']['capabilities']);

        // A notification gets no response line at all — proven here by sending
        // one immediately followed by an ordinary request and asserting the
        // NEXT line off the pipe answers the request, not the notification.
        $this->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $list = $this->request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        self::assertSame(2, $list['id']);
        $names = array_column($list['result']['tools'], 'name');
        self::assertNotSame([], $names, 'At least one D-7 default-profile tool must be reachable.');
        foreach ($names as $name) {
            self::assertContains(
                $name,
                ['bimaaji_introspect_graph', 'bimaaji_introspect_section', 'bimaaji_search_specs'],
                'tools/list must never surface a tool outside the ADR-022 D-7 default allowlist.',
            );
        }
        self::assertContains(
            'bimaaji_search_specs',
            $names,
            'bimaaji_search_specs has no routing dependency and must be reachable from a plain CLI boot.',
        );

        $call = $this->request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'bimaaji_search_specs',
            'arguments' => ['query' => 'entity'],
        ]]);
        self::assertSame(3, $call['id']);
        self::assertArrayHasKey('result', $call, 'stderr was: ' . $this->stderrBuffer);
        self::assertArrayHasKey('content', $call['result']);

        $this->closeStdin();
        $exitCode = $this->waitForExit();
        self::assertSame(0, $exitCode, 'A clean EOF must exit 0. stderr: ' . $this->stderrBuffer);
    }

    #[Test]
    public function an_unknown_tool_call_answers_a_structured_error_result_not_a_protocol_error(): void
    {
        $this->installAndBoot();
        $this->startServer();

        $call = $this->request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'definitely_not_a_real_tool',
            'arguments' => [],
        ]]);

        self::assertArrayNotHasKey('error', $call, 'An unknown TOOL is a tool-level error result, not a JSON-RPC protocol error.');
        self::assertTrue($call['result']['isError']);
    }

    #[Test]
    public function a_malformed_request_gets_a_jsonrpc_error_frame_and_the_session_continues(): void
    {
        $this->installAndBoot();
        $this->startServer();

        $this->writeRawLine('this is not json');
        $malformed = $this->readFrame();
        self::assertSame(-32700, $malformed['error']['code']);

        // The server must still be alive and answering after a parse error.
        $ping = $this->request(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping']);
        self::assertSame(99, $ping['id']);
    }

    #[Test]
    public function construction_refuses_cleanly_outside_a_development_runtime_writing_nothing_to_stdout(): void
    {
        // A second fixture project, config-shaped as production. The kernel
        // itself may refuse boot before this command's own attestation ever
        // runs (both are legitimate "refuses cleanly" outcomes) — what this
        // test proves either way is the ADR-022 D-9.2 acceptance criterion:
        // non-zero exit, and not one byte of stdout.
        file_put_contents($this->projectRoot . '/config/waaseyaa.php', $this->configFile('production'));

        $process = new Process(
            [\PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', 'mcp:serve', '--profile=developer'],
            $this->projectRoot,
            ['WAASEYAA_APP_SECRET' => base64_encode(random_bytes(32))],
        );
        $process->setInput('');
        $process->setTimeout(30);
        $process->run();

        self::assertNotSame(0, $process->getExitCode(), 'A production-shaped runtime must refuse, never serve.');
        self::assertSame('', $process->getOutput(), 'A refused startup must write nothing to stdout.');
    }

    // ---------------------------------------------------------------- fixture lifecycle

    private function installAndBoot(): void
    {
        $dbInit = new Process([\PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', 'db:init'], $this->projectRoot);
        $dbInit->setTimeout(120);
        $dbInit->run();
        self::assertSame(0, $dbInit->getExitCode(), $dbInit->getErrorOutput() . $dbInit->getOutput());

        $install = new Process([\PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', 'install:init'], $this->projectRoot);
        $install->setTimeout(120);
        $install->run();
        self::assertSame(0, $install->getExitCode(), $install->getErrorOutput() . $install->getOutput());
    }

    // ---------------------------------------------------------------- process + pipe plumbing

    private function startServer(): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            [\PHP_BINARY, $this->repoRoot . '/packages/cli/bin/waaseyaa', 'mcp:serve', '--profile=developer'],
            $descriptors,
            $pipes,
            $this->projectRoot,
        );
        self::assertIsResource($process, 'Failed to start mcp:serve.');
        $this->process = $process;
        self::assertCount(3, $pipes);
        /** @var array{0: resource, 1: resource, 2: resource} $pipes */
        $this->pipes = $pipes;

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    private function stopServer(): void
    {
        if ($this->pipes !== null) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = null;
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    /** @param array<string, mixed> $request */
    private function request(array $request): array
    {
        $this->writeRawLine(json_encode($request, JSON_THROW_ON_ERROR));

        return $this->readFrame();
    }

    /** @param array<string, mixed> $notification */
    private function notify(array $notification): void
    {
        $this->writeRawLine(json_encode($notification, JSON_THROW_ON_ERROR));
    }

    private function writeRawLine(string $line): void
    {
        self::assertNotNull($this->pipes);
        fwrite($this->pipes[0], $line . "\n");
    }

    private function closeStdin(): void
    {
        self::assertNotNull($this->pipes);
        fclose($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    private function readFrame(): array
    {
        $line = $this->readLine();
        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'Every line on stdout must be one JSON-RPC frame. Got: ' . $line);
        self::assertSame('2.0', $decoded['jsonrpc'] ?? null);

        return $decoded;
    }

    /**
     * Read one newline-delimited frame off stdout, draining stderr into
     * {@see $stderrBuffer} on every poll so a chatty child can never deadlock
     * this harness by filling its stderr pipe buffer while nobody reads it.
     */
    private function readLine(): string
    {
        self::assertNotNull($this->pipes);
        [, $stdout, $stderr] = $this->pipes;

        $buffer = '';
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $read = [$stdout, $stderr];
            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 0, 200_000);

            if ($changed === false) {
                break;
            }

            foreach ($read as $ready) {
                $chunk = stream_get_contents($ready);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($ready === $stderr) {
                    $this->stderrBuffer .= $chunk;
                    continue;
                }
                $buffer .= $chunk;
            }

            $newlineAt = strpos($buffer, "\n");
            if ($newlineAt !== false) {
                return substr($buffer, 0, $newlineAt);
            }
        }

        self::fail('Timed out waiting for a response frame. Buffered so far: ' . var_export($buffer, true) . ' stderr: ' . $this->stderrBuffer);
    }

    private function waitForExit(): int
    {
        self::assertIsResource($this->process);
        $deadline = microtime(true) + 15.0;
        do {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                return $status['exitcode'];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail('mcp:serve did not exit after stdin closed.');
    }

    private function configFile(string $environment): string
    {
        $databasePath = $this->projectRoot . '/storage/waaseyaa.sqlite';

        return <<<PHP
            <?php

            declare(strict_types=1);

            return [
                'database' => '{$databasePath}',
                'environment' => '{$environment}',
                'app' => ['url' => 'http://localhost', 'name' => 'Stdio MCP conformance fixture'],
            ];
            PHP;
    }
}
