<?php

declare(strict_types=1);

/**
 * ADR-022 D-9.3 probe: run a real, complete tool dispatch in a process where
 * every HTTP class is unreachable, and report which namespaces were touched.
 *
 * **Why an out-of-process probe rather than an in-process assertion.** An
 * in-process test runs under Composer's autoloader, where every HTTP class is
 * already loadable and many are already loaded; asserting "the dispatcher did
 * not use HTTP" there would be a statement about the source text, not about the
 * runtime. This probe removes Composer entirely. It installs a hand-rolled
 * PSR-4 map covering only the three packages the dispatch contracts are allowed
 * to need — `waaseyaa/ai-tools`, `waaseyaa/access`, `waaseyaa/foundation` — and
 * a tripwire autoloader that records any class requested outside them. If the
 * dispatch path reaches for `Symfony\Component\HttpFoundation`,
 * `Waaseyaa\Routing`, `Waaseyaa\Api`, or `Waaseyaa\Mcp`, the class does not
 * resolve and the request is recorded. Success is therefore not "no HTTP class
 * was named" but "a full dispatch completed with HTTP absent from the process".
 *
 * Usage:
 *   php no-http-dispatch-probe.php <repo-root> [--seed-http-touch|--seed-mcp-touch]
 *
 * Output: a single JSON object on stdout.
 *   {"ok": bool, "stage": string, "envelope_text": string, "foreign": [...], "error": ?string}
 *
 * The two `--seed-*` modes are the detector's own controls: they deliberately
 * touch a forbidden class so the harness can prove the tripwire fires. A
 * detector never observed firing is not a detector.
 */

$root = $argv[1] ?? null;
if (!is_string($root) || !is_dir($root)) {
    fwrite(STDERR, "usage: no-http-dispatch-probe.php <repo-root> [--seed-http-touch|--seed-mcp-touch]\n");
    exit(2);
}
$mode = $argv[2] ?? '';

/** PSR-4 roots the transport-neutral dispatch contracts are permitted to need. */
$allowedRoots = [
    'Waaseyaa\\AI\\Tools\\' => $root . '/packages/ai-tools/src/',
    'Waaseyaa\\Access\\' => $root . '/packages/access/src/',
    'Waaseyaa\\Foundation\\' => $root . '/packages/foundation/src/',
];

/** @var list<string> $foreign Classes requested from outside the allowed roots. */
$foreign = [];

spl_autoload_register(static function (string $class) use ($allowedRoots, &$foreign): void {
    foreach ($allowedRoots as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }

    // Anything else is outside the permitted closure. Record it; do not load
    // it. The class then does not exist, and the dispatch fails loudly.
    $foreign[] = $class;
});

$result = ['ok' => false, 'stage' => '', 'envelope_text' => '', 'foreign' => [], 'error' => null];

try {
    if ($mode === '--seed-http-touch') {
        // Detector control: name a Symfony HTTP class the way a leaked HTTP
        // dependency would. The tripwire must record it.
        class_exists('Symfony\\Component\\HttpFoundation\\Request');
    }
    if ($mode === '--seed-mcp-touch') {
        // Detector control: reach for the HTTP MCP package, the exact thing
        // D-9.3 exists to keep out of the local plane's dispatch path.
        class_exists('Waaseyaa\\Mcp\\Bridge\\AgentToolRegistryBridge');
    }

    $principal = new class implements Waaseyaa\Access\AuthorizationPrincipalInterface {
        public function id(): int|string
        {
            return 'probe:principal';
        }

        public function hasPermission(string $permission): bool
        {
            return $permission === 'probe.read';
        }

        public function getRoles(): array
        {
            return [];
        }

        public function isAuthenticated(): bool
        {
            return true;
        }

        public function claimsGeneration(): string
        {
            return 'probe';
        }

        public function tenantId(): ?string
        {
            return null;
        }

        public function communityId(): ?string
        {
            return null;
        }
    };

    $impl = new class implements Waaseyaa\AI\Tools\AgentToolInterface {
        public function execute(array $arguments, Waaseyaa\Access\AuthorizationPrincipalInterface $account): Waaseyaa\AI\Tools\AgentToolResult
        {
            if (!$account->hasPermission('probe.read')) {
                return Waaseyaa\AI\Tools\AgentToolResult::error('Capability required.', 'forbidden');
            }

            return Waaseyaa\AI\Tools\AgentToolResult::success([['type' => 'text', 'text' => 'probe-ok']]);
        }

        public function dryRun(array $arguments, Waaseyaa\Access\AuthorizationPrincipalInterface $account): Waaseyaa\AI\Tools\AgentToolResult
        {
            return $this->execute($arguments, $account);
        }

        public function argumentsForAudit(array $arguments): array
        {
            return ['q' => '[redacted]'];
        }

        public function inputSchema(): array
        {
            return ['type' => 'object', 'required' => ['q'], 'properties' => ['q' => ['type' => 'string']]];
        }

        public function description(): string
        {
            return 'Probe tool.';
        }
    };

    $tool = new Waaseyaa\AI\Tools\AgentTool(
        name: 'probe',
        capability: 'probe.read',
        destructive: false,
        dryRunSupported: false,
        category: 'test',
        inputSchema: $impl->inputSchema(),
        impl: $impl,
    );

    $registry = new class ($tool) implements Waaseyaa\AI\Tools\ToolRegistryInterface {
        /** @var array<string, Waaseyaa\AI\Tools\AgentTool> */
        private array $tools = [];

        public function __construct(Waaseyaa\AI\Tools\AgentTool $tool)
        {
            $this->tools[$tool->name] = $tool;
        }

        public function register(Waaseyaa\AI\Tools\AgentTool $tool): void
        {
            $this->tools[$tool->name] = $tool;
        }

        public function get(string $name): Waaseyaa\AI\Tools\AgentTool
        {
            return $this->tools[$name] ?? throw Waaseyaa\AI\Tools\ToolNotFoundException::forName($name);
        }

        public function has(string $name): bool
        {
            return isset($this->tools[$name]);
        }

        public function all(): iterable
        {
            yield from array_values($this->tools);
        }
    };

    $ledger = new class implements Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface {
        /** @var list<string> */
        public array $calls = [];

        public function reserve(Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation): Waaseyaa\Foundation\Audit\StrictAuditReceipt
        {
            $this->calls[] = 'reserve';

            return new Waaseyaa\Foundation\Audit\StrictAuditReceipt('probe-1', $reservation->correlationId);
        }

        public function finalize(Waaseyaa\Foundation\Audit\StrictAuditReceipt $receipt, Waaseyaa\Foundation\Audit\AuditStage $stage, array $metadata = []): void
        {
            $this->calls[] = 'finalize';
        }

        public function record(Waaseyaa\Foundation\Audit\StrictAuditReservation $reservation, Waaseyaa\Foundation\Audit\AuditStage $stage): void
        {
            $this->calls[] = 'record';
        }
    };

    // The full stack a local stdio transport would build: narrow by capability,
    // narrow by tool id, dispatch, audit.
    $scoped = new Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry($registry, ['probe.read']);
    $allowlisted = new Waaseyaa\AI\Tools\Registry\ToolIdAllowlistRegistry($scoped, ['probe']);
    $dispatcher = new Waaseyaa\AI\Tools\Dispatch\AuditedToolDispatcher(
        new Waaseyaa\AI\Tools\Dispatch\AgentToolDispatcher($allowlisted, $principal),
        $ledger,
        'local.stdio.probe',
        'probe-correlation',
    );

    $names = array_map(
        static fn(Waaseyaa\AI\Tools\AgentTool $t): string => $t->name,
        $dispatcher->tools(),
    );
    $outcome = $dispatcher->dispatch('probe', ['q' => 'hello']);

    $result['stage'] = $outcome->stage->value;
    $result['envelope_text'] = (string) ($outcome->envelope['content'][0]['text'] ?? '');
    $result['tools'] = $names;
    $result['ledger_calls'] = $ledger->calls;
    $result['ok'] = $outcome->stage === Waaseyaa\Foundation\Audit\AuditStage::ExecutionSucceeded;
} catch (\Throwable $e) {
    $result['error'] = $e::class . ': ' . $e->getMessage();
}

$result['foreign'] = array_values(array_unique($foreign));

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
