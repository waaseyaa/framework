<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\LocalOperator;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;

/**
 * ADR-022 D-6 R-1 and R-6, proven from a genuinely HTTP-served runtime.
 *
 * **Why this test has to exist.** The attestation takes a SAPI argument so a
 * test can prove the refusal for a SAPI its own process cannot run under. That
 * seam is only safe if it can *narrow* — add a refusal — and never *widen*.
 * A `$sapi ?? PHP_SAPI` resolution widens: under a real `cli-server` process,
 * passing the string `'cli'` would hand out a principal. A seam that can mint
 * authority is not a seam.
 *
 * **No in-process test can catch that.** The PHPUnit suite runs under `cli`, so
 * `$sapi ?? PHP_SAPI` and "consult `PHP_SAPI` first, then narrow" behave
 * identically here. The difference is only observable from a process whose real
 * SAPI is not `cli`. So this test starts PHP's own built-in server — the same
 * binary, no extra toolchain — which runs under SAPI `cli-server`, and asks it
 * over HTTP to construct the principal with every other gate deliberately
 * satisfied: an explicit development config, the correct transport id, and
 * `'cli'` passed for the seam.
 *
 * Restore the `?? PHP_SAPI` resolution and this test goes red. That is the
 * point of it.
 *
 * It doubles as the end-to-end form of R-1: an HTTP request cannot produce a
 * `LocalOperatorPrincipal`, and `cli-server` is the *friendliest* HTTP SAPI to
 * try it from — it is one of the two in `HttpKernel::DEV_FALLBACK_SAPIS` and
 * one of the three in `DevAdminAccount::ALLOWED_SAPIS`.
 */
#[CoversNothing]
final class LocalOperatorHttpSapiRefusalTest extends TestCase
{
    private ?Process $server = null;
    private string $router = '';
    private int $port = 0;

    protected function tearDown(): void
    {
        $this->server?->stop(2.0);
        $this->server = null;
        if ($this->router !== '') {
            @unlink($this->router);
            $this->router = '';
        }
    }

    /**
     * The control that gives the refusal below its meaning: the server really
     * is running, really is serving this router, and really is `cli-server`.
     */
    #[Test]
    public function the_built_in_server_runs_under_the_cli_server_sapi(): void
    {
        $report = $this->askTheServer();

        self::assertSame('cli-server', $report['sapi']);
        self::assertTrue($report['class_was_loadable'], 'the class must be reachable — this proves refusal, not absence');
    }

    /**
     * R-6 — the narrowing-only seam cannot admit where the runtime refuses.
     *
     * Every other gate is satisfied on purpose. The only thing standing
     * between this HTTP request and a principal is that `PHP_SAPI` is
     * consulted unconditionally and first.
     */
    #[Test]
    public function passing_cli_for_the_seam_cannot_mint_a_principal_under_cli_server(): void
    {
        $report = $this->askTheServer();

        self::assertSame(
            'refused',
            $report['outcome'],
            'A seam that admits where the real runtime refuses is a way to mint authority, not a test seam. '
            . 'Report: ' . json_encode($report, JSON_THROW_ON_ERROR),
        );
        self::assertSame('R-6', $report['row']);
        self::assertStringContainsString('cli-server', (string) $report['message']);
        self::assertNull($report['id'], 'no principal may have been constructed');
    }

    /**
     * The same request under the same server, with the seam left null — so the
     * refusal cannot be attributed to the seam value itself.
     */
    #[Test]
    public function the_refusal_does_not_depend_on_what_the_seam_was_given(): void
    {
        foreach (['cli', null, 'cli-server'] as $seam) {
            $report = $this->askTheServer($seam);

            self::assertSame('refused', $report['outcome'], 'seam=' . var_export($seam, true));
            self::assertSame('R-6', $report['row'], 'seam=' . var_export($seam, true));
        }
    }

    /**
     * Ask the built-in server to attempt construction and return its report.
     *
     * @return array<string, mixed>
     */
    private function askTheServer(?string $seam = 'cli'): array
    {
        $this->startServer();

        $query = $seam === null ? '' : '?seam=' . rawurlencode($seam);
        $body = @file_get_contents(
            sprintf('http://127.0.0.1:%d/%s', $this->port, $query),
            false,
            stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]),
        );
        self::assertIsString($body, 'The built-in server returned nothing.');

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'The server handler must emit JSON. Got: ' . $body);

        return $decoded;
    }

    /**
     * Start PHP's own built-in server on a free loopback port.
     *
     * `symfony/process` rather than `proc_open()`, per #2491: a long-lived
     * child whose stderr is never drained is exactly the shape that wedges a
     * hand-rolled runner. The command is an argv array and `$env` is merged
     * over the inherited environment by the component, so this starts
     * identically on Linux, macOS, and Windows.
     */
    private function startServer(): void
    {
        if ($this->server !== null) {
            return;
        }

        $this->port = self::reserveEphemeralPort();
        $this->router = tempnam(sys_get_temp_dir(), 'local-operator-router-') . '.php';
        file_put_contents($this->router, $this->routerSource(dirname(__DIR__, 3)));

        $server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $this->router],
            null,
            [
                // The most permissive development-shaped process environment
                // available. None of it may matter.
                'APP_ENV' => 'local',
                'APP_DEBUG' => 'true',
                'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'true',
            ],
        );
        // A server runs until it is stopped; a wall-clock timeout would kill it
        // mid-test. tearDown() owns its lifetime.
        $server->setTimeout(null);
        $server->start();
        $this->server = $server;

        // Readiness handshake on a bounded deadline: poll for an accepted
        // connection rather than sleeping a fixed amount and hoping.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            if (!$server->isRunning()) {
                self::fail(sprintf(
                    'The PHP built-in server exited during startup (%d). stderr: %s',
                    (int) $server->getExitCode(),
                    $server->getErrorOutput(),
                ));
            }
            usleep(50_000);
        }

        self::fail(sprintf(
            'The PHP built-in server never accepted a connection on port %d. stderr: %s',
            $this->port,
            $server->getErrorOutput(),
        ));
    }

    /** Bind port 0, read what the OS assigned, release it. */
    private static function reserveEphemeralPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, 'Could not reserve a port: ' . $error);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    private function routerSource(string $root): string
    {
        $autoload = var_export($root . '/vendor/autoload.php', true);
        $principal = LocalOperatorPrincipal::class;
        $attestation = LocalOperatorTransportAttestation::class;

        return <<<PHP
            <?php

            declare(strict_types=1);

            require {$autoload};

            \$report = [
                'outcome' => 'unknown',
                'row' => null,
                'message' => null,
                'id' => null,
                'sapi' => PHP_SAPI,
                'class_was_loadable' => class_exists('{$principal}'),
            ];

            \$seam = isset(\$_GET['seam']) && is_string(\$_GET['seam']) ? \$_GET['seam'] : null;

            try {
                // Every other gate deliberately satisfied: an explicit development
                // environment, the real transport id, and the seam handed the value
                // that would widen if the resolution were `\$sapi ?? PHP_SAPI`.
                \$account = \\{$principal}::forLocalStdioTransport(
                    ['environment' => 'local'],
                    \\{$attestation}::STDIO_TRANSPORT_ID,
                    null,
                    \$seam,
                );
                \$report['outcome'] = 'constructed';
                \$report['id'] = \$account->id();
            } catch (\\Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorRefusal \$refusal) {
                \$report['outcome'] = 'refused';
                \$report['row'] = \$refusal->row;
                \$report['message'] = \$refusal->getMessage();
            } catch (\\Throwable \$error) {
                \$report['outcome'] = 'error';
                \$report['message'] = \$error::class . ': ' . \$error->getMessage();
            }

            header('Content-Type: application/json');
            echo json_encode(\$report, JSON_THROW_ON_ERROR);
            PHP;
    }
}
