<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Message;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Account\InitiatorAccountLoaderInterface;
use Waaseyaa\AI\Agent\AgentDefinition;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\AgentExecutor;
use Waaseyaa\AI\Agent\AgentResult;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Symfony Messenger handler for {@see RunAgent}.
 *
 * The single production entry point that drives an
 * {@see \Waaseyaa\AI\Agent\AgentExecutor} run loop. Responsible for:
 *
 *  - Loading the {@see AgentRun} row from the repository (the message
 *    carries only the run id).
 *  - Enforcing the worker-concurrency guard (NFR-015) via
 *    {@see AgentRunRepository::markRunning()} — a compare-and-swap on
 *    `(status='queued')` that returns false when a sibling worker has
 *    already picked the row up.
 *  - Resolving the run bundle from either the registered
 *    {@see AgentDefinition} (`agent_definition_id`) or the inline
 *    `bundle_json` snapshot, so a definition removed after enqueue still
 *    produces a deterministic run (C-009).
 *  - Pushing the SSE event vocabulary (`run_started`, `run_completed`,
 *    `run_failed`) via {@see AgentRunBroadcasterInterface}; per-iteration
 *    and tool-level events are emitted by the executor itself.
 *  - Catching every {@see \Throwable} so a misbehaving executor cannot
 *    bubble up and crash the Messenger transport — failure modes record
 *    a terminal status and a `run_failed` SSE event before returning.
 *
 * The handler MUST NOT throw to the transport. Persisted state and
 * audit rows are the source of truth for run progress.
 *
 * @api
 */
#[AsMessageHandler]
final class RunAgentHandler
{
    private readonly LoggerInterface $logger;

    /** Injected clock; defaults to the system clock but swappable for tests. */
    private \Closure $now;

    public function __construct(
        private readonly AgentRunRepository $runRepository,
        private readonly AgentExecutor $executor,
        private readonly AgentDefinitionRegistry $definitionRegistry,
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AgentRunBroadcasterInterface $broadcaster,
        private readonly ProviderInterface $provider,
        private readonly InitiatorAccountLoaderInterface $accountLoader,
        ?LoggerInterface $logger = null,
        ?\Closure $now = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    public function __invoke(RunAgent $message): void
    {
        $runId = $message->runId->toRfc4122();

        $run = $this->runRepository->find($runId);
        if ($run === null) {
            $this->logger->warning(\sprintf(
                'RunAgentHandler: run "%s" not found; dropping message.',
                $runId,
            ));

            return;
        }

        // Worker-concurrency guard (NFR-015). markRunning is a CAS on
        // (status='queued'); returning false means another worker has
        // already advanced the row past queued, so exit without
        // re-executing.
        $startedAt = ($this->now)();
        if (!$this->runRepository->markRunning($runId, $startedAt)) {
            $this->logger->info(\sprintf(
                'RunAgentHandler: run "%s" already running or terminal; skipping.',
                $runId,
            ));

            return;
        }

        // Reload to observe the row with started_at + status='running'.
        $run = $this->runRepository->find($runId) ?? $run;

        $this->broadcast($runId, 'run_started', [
            'agent_id' => $run->get('agent_definition_id'),
            'started_at' => $startedAt->format(\DateTimeInterface::ATOM),
        ]);

        try {
            $definition = $this->resolveBundle($run);
            $account = $this->accountLoader->load($run->getAccountId());

            if (!$this->isCapabilityGranted($definition, $account)) {
                $this->refuseForMissingCapability($runId, (string) $definition->requiresCapability);

                return;
            }

            $messages = [
                ['role' => 'user', 'content' => (string) $run->get('prompt')],
            ];
            $tools = $this->allowedToolDescriptors($definition);

            $result = $this->executor->executeRun(
                run: $run,
                initiatorAccount: $account,
                provider: $this->provider,
                messages: $messages,
                system: $definition->system !== '' ? $definition->system : null,
                tools: $tools,
                allowedToolNames: $definition->tools,
                maxIterations: $definition->maxIterations,
            );

            $this->persistResult($runId, $result);

            $fresh = $this->runRepository->find($runId);
            $status = $fresh?->getStatus();

            if ($status === RunStatus::Completed) {
                $this->broadcast($runId, 'run_completed', [
                    'response' => $result->message,
                    'token_usage' => [
                        'input' => $result->tokenUsageIn,
                        'output' => $result->tokenUsageOut,
                    ],
                    'cost_cents' => $result->costCents,
                    'summary' => $result->message,
                ]);

                return;
            }

            if ($status === RunStatus::Cancelled) {
                $this->broadcast($runId, 'run_cancelled', [
                    'cancelled_at' => ($this->now)()->format(\DateTimeInterface::ATOM),
                ]);

                return;
            }

            // Failed (or any other terminal). The executor already wrote
            // markTerminal(Failed, errorCode, errorMessage); emit the SSE.
            $this->broadcast($runId, 'run_failed', [
                'error_code' => $fresh?->get('error_code'),
                'error_message' => $fresh?->get('error_message'),
            ]);
        } catch (\Throwable $e) {
            // Final safety net: the handler MUST NOT propagate to the
            // transport. Persist a terminal Failed (CAS — no-op if the
            // executor already marked it terminal) and emit run_failed.
            //
            // DISPATCH OWNERSHIP: RunAgentHandler dispatches AgentRunTerminated
            // only for supervisor-kill and pre-executor cancellation paths.
            // AgentExecutor owns AgentRunTerminated for normal-completion paths.
            // Exactly one AgentRunTerminated per agent run (FR-005).
            $errorCode = 'handler_exception';
            $errorMessage = $e->getMessage();
            $this->logger->error(\sprintf(
                'RunAgentHandler: run "%s" threw %s: %s',
                $runId,
                $e::class,
                $errorMessage,
            ));

            $finishedAt = ($this->now)();
            $this->runRepository->markTerminal(
                $runId,
                RunStatus::Failed,
                $finishedAt,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
            );

            // Dispatch AgentRunTerminated for this handler-owned failure path.
            $this->dispatchSafely(new AgentRunTerminated(
                runId: $runId,
                status: RunStatus::Failed->value,
                errorCode: $errorCode,
                finishedAt: $finishedAt,
            ));

            $this->broadcast($runId, 'run_failed', [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
        }
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    private function allowedToolDescriptors(AgentDefinition $definition): array
    {
        $descriptors = [];
        foreach ($definition->tools as $name) {
            try {
                $tool = $this->toolRegistry->get($name);
            } catch (ToolNotFoundException $e) {
                $this->logger->warning('RunAgentHandler: allowed tool is not registered.', [
                    'tool' => $name,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }
            $descriptors[] = [
                'name' => $tool->name,
                'description' => $tool->impl->description(),
                'input_schema' => $tool->inputSchema,
            ];
        }

        return $descriptors;
    }

    /**
     * Enforce {@see AgentDefinition::$requiresCapability} against the
     * initiator account (A7 audit F2).
     *
     * `requiresCapability` is a declared, author-facing access gate that
     * used to be plumbed end-to-end (attribute → manifest → registry →
     * definition) and then silently dropped: neither the CLI (`ai:run`)
     * nor the API (`POST /api/ai/agent/run`) entry point ever consulted
     * it before running the agent. Both entries funnel through this
     * handler, so the check lives here — the single chokepoint that
     * cannot be bypassed by either caller.
     *
     * Fail-closed: a null/empty `requiresCapability` means no gate (the
     * common case). Otherwise the initiator MUST have the capability;
     * this covers anonymous and permission-less accounts identically,
     * since {@see \Waaseyaa\Access\AccountInterface::hasPermission()}
     * returns `false` for both.
     */
    private function isCapabilityGranted(AgentDefinition $definition, AccountInterface $account): bool
    {
        $required = $definition->requiresCapability;
        if ($required === null || $required === '') {
            return true;
        }

        return $account->hasPermission($required);
    }

    /**
     * Refuse to execute the run: mark it terminal Failed without ever
     * invoking {@see AgentExecutor::executeRun()} — no tool call, no
     * provider call, no LLM invocation. Mirrors the handler's own
     * exception-path refusal (terminal status + `AgentRunTerminated` +
     * `run_failed` broadcast) but is reached deliberately, not via
     * `catch`.
     */
    private function refuseForMissingCapability(string $runId, string $capability): void
    {
        $errorCode = 'missing_capability';
        $errorMessage = \sprintf(
            'Initiator account lacks required capability "%s"; run refused before execution.',
            $capability,
        );

        $this->logger->warning(\sprintf(
            'RunAgentHandler: run "%s" refused — missing capability "%s".',
            $runId,
            $capability,
        ));

        $finishedAt = ($this->now)();
        $this->runRepository->markTerminal(
            $runId,
            RunStatus::Failed,
            $finishedAt,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );

        $this->dispatchSafely(new AgentRunTerminated(
            runId: $runId,
            status: RunStatus::Failed->value,
            errorCode: $errorCode,
            finishedAt: $finishedAt,
        ));

        $this->broadcast($runId, 'run_failed', [
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Resolve the bundle for the run.
     *
     * Preference order:
     *   1. `bundle_json` if present and non-empty — guarantees that a
     *      run enqueued against a definition that's later renamed or
     *      removed still has the snapshot from enqueue time (C-009).
     *   2. `agent_definition_id` via {@see AgentDefinitionRegistry}.
     *   3. A neutral fallback {@see AgentDefinition} (no system prompt,
     *      no tools) — keeps NullLlmProvider smoke tests viable.
     */
    private function resolveBundle(AgentRun $run): AgentDefinition
    {
        $bundleJson = (string) ($run->get('bundle_json') ?? '');
        if ($bundleJson !== '' && $bundleJson !== '{}') {
            try {
                $decoded = json_decode($bundleJson, true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($decoded) && isset($decoded['id'])) {
                    /** @var array<string, mixed> $decoded */
                    return $this->definitionFromArray($decoded);
                }
            } catch (\JsonException $e) {
                $this->logger->warning(\sprintf(
                    'RunAgentHandler: bundle_json for run "%s" is invalid JSON: %s',
                    (string) $run->get('id'),
                    $e->getMessage(),
                ));
            }
        }

        $definitionId = $run->get('agent_definition_id');
        if (\is_string($definitionId) && $definitionId !== '' && $this->definitionRegistry->has($definitionId)) {
            return $this->definitionRegistry->get($definitionId);
        }

        // Neutral fallback so NullLlmProvider can still drive an empty
        // loop and reach a terminal status.
        return new AgentDefinition(
            id: $definitionId ?? 'ad-hoc',
            label: 'Ad-hoc agent run',
            description: 'No registered definition; running with provider defaults.',
            prompt: (string) ($run->get('prompt') ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function definitionFromArray(array $bundle): AgentDefinition
    {
        $destructiveDefault = null;
        if (isset($bundle['destructive_default']) && \is_string($bundle['destructive_default'])) {
            $destructiveDefault = HitlMode::tryFrom($bundle['destructive_default']);
        }

        $rawTools = $bundle['tools'] ?? [];
        $tools = [];
        if (\is_array($rawTools)) {
            foreach ($rawTools as $tool) {
                if (\is_string($tool) && $tool !== '') {
                    $tools[] = $tool;
                }
            }
        }

        return new AgentDefinition(
            id: (string) ($bundle['id'] ?? 'ad-hoc'),
            label: (string) ($bundle['label'] ?? ''),
            description: (string) ($bundle['description'] ?? ''),
            prompt: (string) ($bundle['prompt'] ?? ''),
            system: (string) ($bundle['system'] ?? ''),
            tools: $tools,
            model: (string) ($bundle['model'] ?? ''),
            maxIterations: (int) ($bundle['max_iterations'] ?? 10),
            destructiveDefault: $destructiveDefault,
            requiresCapability: isset($bundle['requires_capability']) && \is_string($bundle['requires_capability'])
                ? $bundle['requires_capability']
                : null,
        );
    }

    /**
     * Persist transcript / token usage / cost / response on the row.
     *
     * The executor handles status + finished_at via `markTerminal`. This
     * routine ONLY fills the metadata columns that the executor does not
     * write back on the entity itself.
     */
    private function persistResult(string $runId, AgentResult $result): void
    {
        $run = $this->runRepository->find($runId);
        if ($run === null) {
            return;
        }

        $run->set('response', $result->message);
        $run->set('token_usage_in', $result->tokenUsageIn);
        $run->set('token_usage_out', $result->tokenUsageOut);
        $run->set('cost_cents', $result->costCents);

        $transcript = (string) ($result->data['transcript'] ?? '');
        if ($transcript !== '') {
            $run->set('transcript_json', $transcript);
        }

        try {
            $this->runRepository->save($run);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf(
                'RunAgentHandler: failed to persist result metadata for run "%s": %s',
                $runId,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Dispatch an event via the injected dispatcher, swallowing any listener
     * exception so that observability faults never crash the handler.
     *
     * Constitution gotcha: "Best-effort side effects".
     */
    private function dispatchSafely(object $event): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf(
                'RunAgentHandler: event dispatch failed for %s: %s',
                $event::class,
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function broadcast(string $runId, string $event, array $data): void
    {
        try {
            $this->broadcaster->push($runId, $event, $data);
        } catch (\Throwable $e) {
            // Broadcast failures are best-effort. The interface
            // implementations swallow internally, but we keep an outer
            // guard for paranoia.
            $this->logger->error(\sprintf(
                'RunAgentHandler: broadcast "%s" failed for run "%s": %s',
                $event,
                $runId,
                $e->getMessage(),
            ));
        }
    }
}
