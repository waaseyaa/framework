<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Command\Ai;

use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Security\AgentRunWorkerReaderInterface;
use Waaseyaa\AI\Agent\Service\AgentRunDraft;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\SseLineStreamInterface;

/**
 * `bin/waaseyaa ai:run <prompt> [...]` — the operator's primary entry to
 * the agent runtime (FR-005).
 *
 * Two execution modes:
 *  - **dispatch (default):** persists a queued {@see AgentRun} via
 *    {@see AgentRunService::enqueue()}, dispatches a {@see \Waaseyaa\AI\Agent\Message\RunAgent}
 *    onto the Messenger bus, and prints the new `run_id`. The framework's
 *    default bus handles synchronously in the foreground; applications with
 *    an asynchronous transport return after dispatch for a worker to consume.
 *  - **inline (`--inline`):** persists a queued {@see AgentRun} and runs
 *    the worker handler in-process via {@see AgentRunService::runInline()}.
 *    Used for smoke tests and dev iteration; the wall-clock target is
 *    < 10 s on `NullLlmProvider` (NFR-001).
 *
 * Edge case: `--inline` + `--destructive-approval=interactive` is
 * rejected at parse time — no human is present in the calling process
 * to answer the approval prompt.
 *
 * @api
 */
final class AiRunCommand
{
    public const HITL_NONE = 'none';
    public const HITL_ALL = 'all';
    public const HITL_INTERACTIVE = 'interactive';

    /** Set to true by the SIGINT handler to abort the SSE consumer loop. */
    private bool $interrupted = false;

    public function __construct(
        private readonly AgentRunService $runService,
        private readonly AgentDefinitionRegistry $definitionRegistry,
        private readonly AgentRunWorkerReaderInterface $workerReader,
        /** @var array<string, mixed> */
        private readonly array $aiConfig = [],
        private readonly int|string $serviceAccountId = 0,
        private readonly ?SseLineStreamInterface $sseClient = null,
        private readonly string $baseUrl = '',
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        // Command services can be reused in long-lived processes and tests.
        // A SIGINT from a prior --watch invocation must not cancel the next one.
        $this->interrupted = false;

        $prompt = $io->argument('prompt');
        if (!\is_string($prompt) || trim($prompt) === '') {
            $io->error('ai:run: <prompt> argument is required.');
            return 1;
        }

        $inline = (bool) $io->option('inline');
        $hitlRaw = $io->option('destructive-approval');
        $hitl = \is_string($hitlRaw) && $hitlRaw !== '' ? $hitlRaw : self::HITL_NONE;

        if (!\in_array($hitl, [self::HITL_NONE, self::HITL_ALL, self::HITL_INTERACTIVE], true)) {
            $io->error(\sprintf(
                'ai:run: --destructive-approval must be one of "none", "all", or "interactive"; got "%s".',
                $hitl,
            ));
            return 1;
        }

        // Spec edge case: --inline + interactive HITL is impossible (no human present).
        if ($inline && $hitl === self::HITL_INTERACTIVE) {
            $io->error(
                'ai:run: --inline cannot be combined with --destructive-approval=interactive. '
                . 'No human is present in the calling process to answer approval prompts. '
                . 'Use --destructive-approval=none or =all for inline runs, or drop --inline.',
            );
            return 1;
        }

        $hitlMode = match ($hitl) {
            self::HITL_NONE => HitlMode::None,
            self::HITL_ALL => HitlMode::All,
            self::HITL_INTERACTIVE => HitlMode::Interactive,
        };

        $agentId = $io->option('agent');
        $agentId = \is_string($agentId) && $agentId !== '' ? $agentId : null;

        $accountOption = $io->option('account');
        $accountId = $this->resolveAccountId($accountOption);

        $dryRun = (bool) $io->option('dry-run');
        $watch = (bool) $io->option('watch');

        $bundle = null;
        if ($agentId !== null) {
            if (!$this->definitionRegistry->has($agentId)) {
                $io->error(\sprintf('ai:run: agent definition "%s" is not registered.', $agentId));
                return 1;
            }
        } else {
            // No explicit agent: build an ad-hoc bundle from config.ai.providers[0]'s default model.
            $bundle = $this->buildDefaultBundle($dryRun);
            if ($bundle === null) {
                $io->error(
                    'ai:run: no --agent supplied and config.ai.providers[0] is missing or has no '
                    . 'model_default. Supply --agent=<id> or configure at least one provider.',
                );
                return 1;
            }
        }

        if ($dryRun && $bundle !== null) {
            $bundle['dry_run'] = true;
        }

        $draft = new AgentRunDraft(
            accountId: $accountId,
            agentDefinitionId: $agentId,
            bundle: $bundle,
            prompt: $prompt,
            destructiveApproval: $hitlMode,
        );

        if ($inline) {
            return $this->runInline($io, $draft);
        }

        return $this->runAsync($io, $draft, $watch);
    }

    private function runInline(SymfonyCommandIO $io, AgentRunDraft $draft): int
    {
        try {
            $run = $this->runService->runInline($draft);
        } catch (\Throwable $e) {
            $io->error(\sprintf('ai:run --inline: %s', $e->getMessage()));
            return 1;
        }

        $this->printRunSummary($io, $run);

        return $run->isTerminal() && $run->getStatus()->value === 'failed' ? 1 : 0;
    }

    private function runAsync(SymfonyCommandIO $io, AgentRunDraft $draft, bool $watch): int
    {
        try {
            $run = $this->runService->enqueue($draft);
        } catch (\Throwable $e) {
            $io->error(\sprintf('ai:run: %s', $e->getMessage()));
            return 1;
        }

        $runId = (string) $run->get('id');
        $io->writeln(\sprintf('run_id: %s', $runId));
        $io->writeln(\sprintf('status: %s', (string) $run->get('status')));

        if ($watch) {
            $url = \sprintf(
                '%s/broadcast?channels=agent.run.%s',
                rtrim($this->resolveBaseUrl(), '/'),
                $runId,
            );
            $io->writeln(\sprintf('<info>Watching agent run %s…</info>', $runId));
            $this->registerSigintHandler();
            return $this->consumeSseStream($url, $io);
        }

        return 0;
    }

    /**
     * Consume an SSE stream line-by-line, printing each event to stdout.
     * Exits cleanly when the `terminated` event arrives or the stream closes.
     */
    private function consumeSseStream(string $url, SymfonyCommandIO $io): int
    {
        $client = $this->sseClient;
        if ($client === null) {
            $io->error('watch: no SSE client configured (missing waaseyaa/http-client dependency).');
            return 1;
        }

        try {
            $eventName = '';
            foreach ($client->lines($url) as $line) {
                // Dispatch any pending signals (SIGINT) on each line.
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                if ($this->interrupted) {
                    $io->writeln('<comment>Interrupted — agent run continues server-side.</comment>');
                    if ($client instanceof \Waaseyaa\HttpClient\PhpStreamSseClient) {
                        $client->close();
                    }
                    return 0;
                }

                if (\str_starts_with($line, 'event:')) {
                    $eventName = trim(\substr($line, 6));
                } elseif (\str_starts_with($line, 'data:')) {
                    $data = trim(\substr($line, 5));
                    $io->writeln(\sprintf('[%s] %s', $eventName !== '' ? $eventName : 'message', $data));
                } elseif ($line === '') {
                    // End of SSE message block; reset pending event name.
                    if ($this->isTerminatedEvent($eventName)) {
                        break;
                    }
                    $eventName = '';
                }
            }
        } catch (HttpRequestException $e) {
            $io->error(\sprintf('watch: SSE connection failed — %s', $e->getMessage()));
            return 1;
        }

        return 0;
    }

    private function isTerminatedEvent(string $eventName): bool
    {
        return \in_array($eventName, ['agent.run.terminated', 'terminated'], true);
    }

    private function registerSigintHandler(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return; // Graceful degradation if pcntl extension is unavailable.
        }
        \pcntl_signal(\SIGINT, function (): void {
            $this->interrupted = true;
        });
    }

    private function resolveBaseUrl(): string
    {
        if ($this->baseUrl !== '') {
            return $this->baseUrl;
        }
        $envVal = $_ENV['WAASEYAA_BASE_URL'] ?? null;
        if (\is_string($envVal) && $envVal !== '') {
            return $envVal;
        }
        $getenvVal = getenv('WAASEYAA_BASE_URL');
        if (\is_string($getenvVal) && $getenvVal !== '') {
            return $getenvVal;
        }
        return 'http://localhost:8000';
    }

    private function printRunSummary(SymfonyCommandIO $io, AgentRun $run): void
    {
        $runId = (string) $run->get('id');
        $status = (string) $run->get('status');
        $response = $this->workerReader->read($run)->response;

        $io->writeln(\sprintf('run_id: %s', $runId));
        $io->writeln(\sprintf('status: %s', $status));
        if (\is_string($response) && $response !== '') {
            $io->writeln('response:');
            $io->writeln($response);
        }
    }

    /**
     * Build an ad-hoc bundle pointing at `config.ai.providers[0]`'s default
     * model, returning null if no provider is configured.
     *
     * @return array<string, mixed>|null
     */
    private function buildDefaultBundle(bool $dryRun): ?array
    {
        $providers = $this->aiConfig['providers'] ?? null;
        if (!\is_array($providers) || $providers === []) {
            return null;
        }

        $first = $providers[0] ?? null;
        if (!\is_array($first)) {
            return null;
        }

        $modelDefault = $first['model_default'] ?? null;
        if (!\is_string($modelDefault) || $modelDefault === '') {
            return null;
        }

        $providerId = $first['id'] ?? null;

        $bundle = [
            'model' => $modelDefault,
            'tools' => [],
        ];
        if (\is_string($providerId) && $providerId !== '') {
            $bundle['provider'] = $providerId;
        }
        if ($dryRun) {
            $bundle['dry_run'] = true;
        }

        return $bundle;
    }

    private function resolveAccountId(string|int|float|bool|array|null $option): int|string
    {
        if (\is_int($option)) {
            return $option;
        }
        if (\is_string($option) && $option !== '') {
            if (ctype_digit($option)) {
                return (int) $option;
            }
            return $option;
        }
        return $this->serviceAccountId;
    }
}
