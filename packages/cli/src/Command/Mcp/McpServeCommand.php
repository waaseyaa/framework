<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Mcp;

use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorRefusal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;
use Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher;
use Waaseyaa\AI\Tools\Dispatch\AuditedToolDispatcher;
use Waaseyaa\AI\Tools\Dispatch\ToolDispatchOutcome;
use Waaseyaa\AI\Tools\Registry\ToolIdAllowlistRegistry;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Mcp\Stdio\StdioMcpServer;
use Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * `mcp:serve` — the local stdio MCP transport (ADR-022 D-9.2, #2659).
 *
 * Constructs {@see LocalOperatorPrincipal} through the #2658 attestation,
 * narrows the framework-wide tool registry to its D-7 allowlist, and hands
 * both to a {@see StdioMcpServer} reading JSON-RPC requests from stdin and
 * writing responses to stdout. **Nothing else writes to stdout.** Every
 * diagnostic — including the structured refusal when the attestation fails,
 * an unsupported `--profile`, or an audit-ledger construction failure — goes
 * to stderr via {@see SymfonyCommandIO::error()}, so a client speaking the
 * wire protocol never receives a byte it did not ask for, and this command
 * exits non-zero on every one of those refusals rather than starting a
 * session it cannot honour.
 *
 * Reuses the transport-neutral dispatch rather than forking it: tool
 * execution goes through {@see AgentToolDispatcher} and {@see
 * AuditedToolDispatcher}, the exact classes the HTTP MCP tiers use
 * (`Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge` is a façade over the former;
 * this command is the stdio transport's own façade over both).
 *
 * @api
 */
final class McpServeCommand
{
    /**
     * The local plane's dedicated audit surface (ADR-022 D-5.C.5). Deliberately
     * not `mcp` or `mcp.*` — those are the HTTP tiers' own identifiers
     * (`McpEndpoint::auditSurface()` writes `mcp.public` / `mcp.write`), and
     * {@see AuditedToolDispatcher} refuses construction on a collision so a
     * local developer session can never be mistaken for a network caller on
     * inspection of the ledger.
     */
    public const string AUDIT_SURFACE = 'waaseyaa.local.stdio';

    /** The only profile this command accepts today (ADR-022 D-7's default allowlist). */
    private const string SUPPORTED_PROFILE = 'developer';

    /**
     * @param array<string, mixed> $runtimeConfig The kernel's resolved config
     *        array, forwarded verbatim to
     *        {@see LocalOperatorTransportAttestation}. Only its `environment`
     *        key is read, and only via `RuntimePolicy::isExplicitDevelopment()`
     *        — this command never widens that gate.
     * @param resource $in  Overridable for tests; defaults to real STDIN.
     * @param resource $out Overridable for tests; defaults to real STDOUT.
     */
    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly StrictAuditLedgerInterface $auditLedger,
        private readonly array $runtimeConfig,
        private readonly string $serverVersion,
        private readonly ?LoggerInterface $logger = null,
        $in = STDIN,
        $out = STDOUT,
    ) {
        $this->in = $in;
        $this->out = $out;
    }

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    public function execute(SymfonyCommandIO $io): int
    {
        $logger = $this->logger ?? new NullLogger();

        $profile = $io->option('profile');
        $profile = \is_string($profile) && $profile !== '' ? $profile : self::SUPPORTED_PROFILE;
        if ($profile !== self::SUPPORTED_PROFILE) {
            $io->error($this->diagnosticFrame(
                'unsupported_profile',
                \sprintf(
                    'Only the "%s" profile exists today (ADR-022 D-7 — the read-only structural allowlist); "%s" was requested.',
                    self::SUPPORTED_PROFILE,
                    $profile,
                ),
            ));

            return 1;
        }

        try {
            $principal = LocalOperatorPrincipal::forLocalStdioTransport(
                $this->runtimeConfig,
                LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            );
        } catch (LocalOperatorRefusal $refusal) {
            $io->error($this->diagnosticFrame('local_operator_refused', $refusal->getMessage(), ['row' => $refusal->row]));

            return 1;
        }

        $scoped = new ToolIdAllowlistRegistry($this->toolRegistry, $principal->toolProfile()->toolIds());
        $catalogue = new AgentToolDispatcher($scoped, $principal, $logger, 'local_stdio');
        $ledger = $this->auditLedger;

        try {
            // Construction-only probe: `AuditedToolDispatcher` validates D-5.A
            // (the ledger must be real, never `NullStrictAuditLedger`) and
            // D-5.C (the surface must not collide with an HTTP MCP identifier)
            // in its constructor. Triggering that check once, here, means a
            // misconfigured host refuses at startup — before a client believes
            // a session is open — rather than on the first `tools/call`. The
            // probe instance itself is discarded; every REAL dispatch builds
            // its own instance with a fresh per-request correlation id
            // (D-5.C.6), which a single shared instance could not provide.
            new AuditedToolDispatcher($catalogue, $ledger, self::AUDIT_SURFACE, 'startup-probe', null, [], $logger);
        } catch (\LogicException $e) {
            $io->error($this->diagnosticFrame('audit_dispatcher_refused', $e->getMessage()));

            return 1;
        }

        $dispatch = function (string $name, array $arguments, string $correlationId) use ($catalogue, $ledger, $principal, $logger): ToolDispatchOutcome {
            $audited = new AuditedToolDispatcher(
                $catalogue,
                $ledger,
                self::AUDIT_SURFACE,
                $correlationId,
                $principal->auditActorUid(),
                $principal->auditMetadata(),
                $logger,
            );

            return $audited->dispatch($name, $arguments);
        };

        $server = new StdioMcpServer(
            catalogue: $catalogue,
            dispatch: $dispatch,
            serverName: 'waaseyaa',
            serverVersion: $this->serverVersion,
            in: $this->in,
            out: $this->out,
            diagnostic: static fn(string $line) => $io->error($line),
        );

        return $server->run();
    }

    /** @param array<string, mixed> $extra */
    private function diagnosticFrame(string $code, string $message, array $extra = []): string
    {
        return json_encode(['error' => $code, 'message' => $message] + $extra, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
