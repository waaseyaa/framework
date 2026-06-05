<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Entity\AgentRun;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\HitlMode;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Provider\ClientErrorException;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Provider\RateLimitException;
use Waaseyaa\AI\Agent\Provider\ToolResultBlock;
use Waaseyaa\AI\Agent\Provider\TransportException;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Observability\Event\AgentRunIterationCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunProviderCallCompleted;
use Waaseyaa\AI\Observability\Event\AgentRunStarted;
use Waaseyaa\AI\Observability\Event\AgentRunTerminated;
use Waaseyaa\AI\Observability\Event\AgentRunToolCallObserved;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Run-loop driver for an {@see AgentRun}.
 *
 * Owns the lifecycle of a run from `queued → running → terminal`. Each
 * provider call, tool call, approval gate, and error path is persisted
 * as a row through {@see AgentAuditLogRepository::append()} (C-014). The
 * legacy in-memory `$auditLog[]` array has been removed; all audit data
 * lives in the persisted `agent_audit_log` table.
 *
 * Responsibilities:
 *
 *  - Resolve `AgentTool` instances from the new ai-tools registry and
 *    dispatch tool calls against `AgentToolInterface::execute()` (FR-014,
 *    FR-018).
 *  - Implement the three HITL modes — `None`, `All`, `Interactive`
 *    (FR-020, NFR-005).
 *  - Poll the `AgentRun` status row at iteration boundaries and immediately
 *    before tool invocation to honour cancellation requests (FR-024,
 *    NFR-003).
 *  - Retry provider failures with exponential backoff, capped at 30 s,
 *    capped at 3 retries (FR-025).
 *  - Surface token usage and USD-cent cost on the returned
 *    {@see AgentResult} (FR-023).
 *  - Enforce the transcript size cap from `config.ai.transcript_max_bytes`
 *    at write boundaries (NFR-007).
 *
 * @api
 */
final class AgentExecutor
{
    /** Maximum retry attempts for transient provider failures (FR-025). */
    private const int PROVIDER_MAX_RETRIES = 3;

    /** Base backoff in ms; doubled per attempt then capped at 30s. */
    private const int PROVIDER_BACKOFF_BASE_MS = 1000;

    /** Hard cap per backoff sleep (30 s, FR-025). */
    private const int PROVIDER_BACKOFF_CAP_MS = 30000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ToolRegistryInterface $toolRegistry,
        private readonly AgentRunRepository $runRepository,
        private readonly AgentAuditLogRepository $auditRepository,
        private readonly int $transcriptMaxBytes = 262144,
        private readonly int $hitlPollIntervalMs = 1000,
        private readonly int $hitlTimeoutSeconds = 300,
        ?LoggerInterface $logger = null,
        ?\Closure $sleepMs = null,
        ?\Closure $now = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sleepMs = $sleepMs ?? static function (int $ms): void {
            if ($ms > 0) {
                \usleep($ms * 1000);
            }
        };
        $this->now = $now ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    /**
     * Injected sleeper. Defaults to {@see usleep()} but is swappable for
     * tests so retry/HITL polling backoffs do not pace the test suite.
     *
     * @var \Closure(int): void
     */
    private \Closure $sleepMs;

    /**
     * Injected clock. Defaults to `new DateTimeImmutable('now')` but is
     * swappable for tests so HITL timeouts can be exercised deterministically.
     *
     * @var \Closure(): \DateTimeImmutable
     */
    private \Closure $now;

    /**
     * Drive an {@see AgentRun} to a terminal status.
     *
     * The run row is reloaded between provider attempts and before each
     * tool call so that cancellation requests issued via the API surface
     * are honoured within `3 iterations + 1 tool call` (NFR-003).
     *
     * @param array<int, array<string, mixed>> $messages Initial messages.
     * @param array<int, array<string, mixed>> $tools     Tool descriptors to advertise to the provider.
     * @param int $maxIterations Iteration budget (FR-026).
     * @param int $maxTokens Provider per-call token budget.
     */
    public function executeRun(
        AgentRun $run,
        AccountInterface $initiatorAccount,
        ProviderInterface $provider,
        array $messages,
        ?string $system = null,
        array $tools = [],
        int $maxIterations = 10,
        int $maxTokens = 4096,
    ): AgentResult {
        $runId = (string) $run->get('id');
        $hitl = $run->getDestructiveApproval();
        $iteration = 0;
        $transcript = '';
        $tokenIn = 0;
        $tokenOut = 0;
        $costCents = null;
        $finalText = '';
        $runStartedAt = ($this->now)();

        // DISPATCH OWNERSHIP: AgentExecutor dispatches AgentRunStarted, AgentRunIterationCompleted,
        // AgentRunProviderCallCompleted, AgentRunToolCallObserved, and AgentRunTerminated for
        // normal-completion paths. RunAgentHandler dispatches AgentRunTerminated only for
        // supervisor-kill and pre-executor cancellation. Exactly one AgentRunTerminated per
        // agent run (FR-005).
        $this->dispatchSafely(new AgentRunStarted(
            runId: $runId,
            agentDefinitionId: \is_string($run->get('agent_definition_id')) ? $run->get('agent_definition_id') : null,
            accountId: (int) $initiatorAccount->id(),
            startedAt: $runStartedAt,
        ));

        while (true) {
            $iteration++;
            if ($iteration > $maxIterations) {
                $this->appendError(
                    $runId,
                    $iteration,
                    'max_iterations',
                    sprintf('Exceeded max iterations (%d).', $maxIterations),
                );
                $this->runRepository->markTerminal(
                    $runId,
                    RunStatus::Failed,
                    ($this->now)(),
                    errorCode: 'max_iterations',
                    errorMessage: sprintf('Exceeded max iterations (%d).', $maxIterations),
                );

                return AgentResult::failure(
                    message: sprintf('Exceeded max iterations (%d).', $maxIterations),
                    data: ['iterations' => $iteration - 1, 'run_id' => $runId],
                    tokenUsageIn: $tokenIn,
                    tokenUsageOut: $tokenOut,
                    costCents: $costCents,
                );
            }

            // Iteration-entry cancellation poll (NFR-003).
            if ($this->checkCancellation($runId, $iteration)) {
                return AgentResult::failure(
                    message: 'Run cancelled.',
                    data: ['iterations' => $iteration - 1, 'run_id' => $runId, 'cancelled' => true],
                    tokenUsageIn: $tokenIn,
                    tokenUsageOut: $tokenOut,
                    costCents: $costCents,
                );
            }

            $this->appendAudit($runId, $iteration, EventType::IterationStart, success: true);
            $iterationStartMs = microtime(true);

            $request = new MessageRequest(
                messages: $messages,
                system: $system,
                tools: $tools,
                maxTokens: $maxTokens,
            );

            // Provider retry loop (FR-025).
            $response = $this->callProviderWithRetry($provider, $request, $runId, $iteration);
            if ($response === null) {
                // Terminal status already written; return a failure result.
                return AgentResult::failure(
                    message: 'Provider exhausted retries.',
                    data: ['iterations' => $iteration, 'run_id' => $runId],
                    tokenUsageIn: $tokenIn,
                    tokenUsageOut: $tokenOut,
                    costCents: $costCents,
                );
            }

            // Dispatch provider-call-completed event (FR-012).
            // ProviderInterface does not expose provider/model names; use the
            // class short-name as a best-effort identifier.
            $this->dispatchSafely(new AgentRunProviderCallCompleted(
                runId: $runId,
                provider: new \ReflectionClass($provider)->getShortName(),
                model: 'unknown',
                tokensIn: $response->usage['input_tokens'],
                tokensOut: $response->usage['output_tokens'],
            ));

            $tokenIn += $response->usage['input_tokens'];
            $tokenOut += $response->usage['output_tokens'];

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $transcript = $this->appendTranscript($transcript, json_encode($response->content, JSON_THROW_ON_ERROR));

            if ($response->stopReason !== 'tool_use') {
                $finalText = self::extractText($response);
                // Dispatch iteration-completed before breaking (final iteration). FR-011.
                $this->dispatchSafely(new AgentRunIterationCompleted(
                    runId: $runId,
                    iterationIndex: $iteration - 1,
                    durationMs: (int) round((microtime(true) - $iterationStartMs) * 1000),
                ));
                break;
            }

            // Tool-call dispatch.
            $toolResults = [];
            foreach ($response->getToolUseBlocks() as $toolUseBlock) {
                // Per-tool-call cancellation poll (NFR-003).
                if ($this->checkCancellation($runId, $iteration)) {
                    return AgentResult::failure(
                        message: 'Run cancelled.',
                        data: ['iterations' => $iteration, 'run_id' => $runId, 'cancelled' => true],
                        tokenUsageIn: $tokenIn,
                        tokenUsageOut: $tokenOut,
                        costCents: $costCents,
                    );
                }

                $toolName = $toolUseBlock->name;
                $toolArgs = $toolUseBlock->input;

                try {
                    $tool = $this->toolRegistry->get($toolName);
                } catch (ToolNotFoundException $e) {
                    $this->appendError($runId, $iteration, 'tool_not_found', $e->getMessage(), $toolName, $toolArgs);
                    $toolResults[] = new ToolResultBlock(
                        toolUseId: $toolUseBlock->id,
                        content: json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                        isError: true,
                    )->toArray();
                    continue;
                }

                // HITL gate for destructive tools (FR-020).
                if ($tool->destructive) {
                    $gate = $this->applyHitlGate($run, $hitl, $tool, $toolUseBlock->id, $iteration);
                    if ($gate === self::HITL_DENIED) {
                        return AgentResult::failure(
                            message: sprintf('Destructive tool "%s" denied (mode=%s).', $toolName, $hitl->value),
                            data: ['run_id' => $runId, 'tool' => $toolName, 'error_code' => 'destructive_denied'],
                            tokenUsageIn: $tokenIn,
                            tokenUsageOut: $tokenOut,
                            costCents: $costCents,
                        );
                    }
                    if ($gate === self::HITL_TIMEOUT) {
                        return AgentResult::failure(
                            message: sprintf('Approval timeout for tool "%s".', $toolName),
                            data: ['run_id' => $runId, 'tool' => $toolName, 'error_code' => 'approval_timeout'],
                            tokenUsageIn: $tokenIn,
                            tokenUsageOut: $tokenOut,
                            costCents: $costCents,
                        );
                    }
                    if ($gate === self::HITL_DENIED_INTERACTIVE) {
                        return AgentResult::failure(
                            message: sprintf('Approval denied for tool "%s".', $toolName),
                            data: ['run_id' => $runId, 'tool' => $toolName, 'error_code' => 'approval_denied'],
                            tokenUsageIn: $tokenIn,
                            tokenUsageOut: $tokenOut,
                            costCents: $costCents,
                        );
                    }
                    // GRANTED — fall through.
                }

                $toolCallStart = microtime(true);
                $auditArgs = $tool->impl->argumentsForAudit($toolArgs);
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ToolCall,
                    success: true,
                    toolName: $toolName,
                    toolArgumentsJson: json_encode($auditArgs, JSON_THROW_ON_ERROR),
                );

                try {
                    $toolResult = $tool->impl->execute($toolArgs, $initiatorAccount);
                } catch (\Throwable $e) {
                    $this->logger->error(sprintf('Tool "%s" threw: %s', $toolName, $e->getMessage()));
                    $this->appendAudit(
                        $runId,
                        $iteration,
                        EventType::Error,
                        success: false,
                        toolName: $toolName,
                        toolResultSummary: $e->getMessage(),
                        durationMs: self::msSince($toolCallStart),
                    );
                    $toolResults[] = new ToolResultBlock(
                        toolUseId: $toolUseBlock->id,
                        content: json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR),
                        isError: true,
                    )->toArray();
                    // Dispatch tool-call-observed for the failed (threw) path. FR-013.
                    $this->dispatchSafely(new AgentRunToolCallObserved(
                        runId: $runId,
                        toolName: $toolName,
                        succeeded: false,
                    ));
                    continue;
                }

                $resultEvent = $toolResult->isError ? EventType::Error : EventType::ToolResult;
                $this->appendAudit(
                    $runId,
                    $iteration,
                    $resultEvent,
                    success: !$toolResult->isError,
                    toolName: $toolName,
                    toolResultSummary: $toolResult->summary,
                    durationMs: self::msSince($toolCallStart),
                );

                $resultText = self::toolResultToText($toolResult);
                $transcript = $this->appendTranscript($transcript, $resultText);

                $toolResults[] = new ToolResultBlock(
                    toolUseId: $toolUseBlock->id,
                    content: $resultText,
                    isError: $toolResult->isError,
                )->toArray();

                // Dispatch tool-call-observed event after terminal lifecycle
                // (completed or failed). FR-013.
                $this->dispatchSafely(new AgentRunToolCallObserved(
                    runId: $runId,
                    toolName: $toolName,
                    succeeded: !$toolResult->isError,
                ));
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];

            // Dispatch iteration-completed event at the bottom of each loop
            // iteration (after tool calls resolved, before next iteration). FR-011.
            $this->dispatchSafely(new AgentRunIterationCompleted(
                runId: $runId,
                iterationIndex: $iteration - 1,
                durationMs: (int) round((microtime(true) - $iterationStartMs) * 1000),
            ));
        }

        $finishedAt = ($this->now)();
        $this->runRepository->markTerminal($runId, RunStatus::Completed, $finishedAt);

        // Dispatch terminal event for normal-completion path. RunAgentHandler
        // dispatches AgentRunTerminated for its owned paths (cancelled/pre-executor).
        $this->dispatchSafely(new AgentRunTerminated(
            runId: $runId,
            status: RunStatus::Completed->value,
            errorCode: null,
            finishedAt: $finishedAt,
        ));

        return AgentResult::success(
            message: $finalText,
            data: ['iterations' => $iteration, 'run_id' => $runId, 'transcript' => $transcript],
            tokenUsageIn: $tokenIn,
            tokenUsageOut: $tokenOut,
            costCents: $costCents,
        );
    }

    /**
     * Execute a single tool by name on behalf of an external caller.
     *
     * Used by mission-internal tooling that wants to invoke an
     * `AgentTool` without going through the LLM loop. Audit rows are
     * written when a `$runId` is supplied.
     *
     * @param array<string, mixed> $arguments
     */
    public function executeTool(
        string $toolName,
        array $arguments,
        AccountInterface $account,
        ?string $runId = null,
        int $iteration = 0,
    ): AgentToolResult {
        try {
            $tool = $this->toolRegistry->get($toolName);
        } catch (ToolNotFoundException $e) {
            if ($runId !== null) {
                $this->appendError($runId, $iteration, 'tool_not_found', $e->getMessage(), $toolName, $arguments);
            }

            return AgentToolResult::error($e->getMessage());
        }

        try {
            return $tool->impl->execute($arguments, $account);
        } catch (\Throwable $e) {
            if ($runId !== null) {
                $this->appendError(
                    $runId,
                    $iteration,
                    'tool_exception',
                    $e->getMessage(),
                    $toolName,
                    $arguments,
                );
            }
            $this->logger->error(sprintf('executeTool("%s") threw: %s', $toolName, $e->getMessage()));

            return AgentToolResult::error($e->getMessage());
        }
    }

    private const string HITL_GRANTED = 'granted';
    private const string HITL_DENIED = 'denied';
    private const string HITL_TIMEOUT = 'timeout';
    private const string HITL_DENIED_INTERACTIVE = 'denied_interactive';

    /**
     * Apply the HITL gate for a destructive tool call (FR-020 / NFR-005).
     */
    private function applyHitlGate(
        AgentRun $run,
        HitlMode $mode,
        AgentTool $tool,
        string $callId,
        int $iteration,
    ): string {
        $runId = (string) $run->get('id');

        return match ($mode) {
            HitlMode::None => $this->hitlDenyNone($runId, $iteration, $tool),
            HitlMode::All => $this->hitlGrantAll($runId, $iteration, $tool),
            HitlMode::Interactive => $this->hitlInteractive($run, $tool, $callId, $iteration),
        };
    }

    private function hitlDenyNone(string $runId, int $iteration, AgentTool $tool): string
    {
        $this->appendAudit(
            $runId,
            $iteration,
            EventType::ApprovalDenied,
            success: false,
            toolName: $tool->name,
            toolResultSummary: 'destructive_denied (mode=none)',
        );
        $this->runRepository->markTerminal(
            $runId,
            RunStatus::Failed,
            ($this->now)(),
            errorCode: 'destructive_denied',
            errorMessage: sprintf('Destructive tool "%s" rejected under mode=none.', $tool->name),
        );

        return self::HITL_DENIED;
    }

    private function hitlGrantAll(string $runId, int $iteration, AgentTool $tool): string
    {
        $this->appendAudit(
            $runId,
            $iteration,
            EventType::ApprovalGranted,
            success: true,
            toolName: $tool->name,
            toolResultSummary: 'blanket approval (mode=all)',
        );

        return self::HITL_GRANTED;
    }

    private function hitlInteractive(
        AgentRun $run,
        AgentTool $tool,
        string $callId,
        int $iteration,
    ): string {
        $runId = (string) $run->get('id');

        $run->set('status', RunStatus::AwaitingApproval->value);
        $run->set('pending_approval_call_id', $callId);
        $this->runRepository->save($run);

        $this->appendAudit(
            $runId,
            $iteration,
            EventType::ApprovalRequired,
            success: true,
            toolName: $tool->name,
            toolResultSummary: sprintf('awaiting approval (call_id=%s)', $callId),
        );

        $deadline = ($this->now)()->add(new \DateInterval('PT' . $this->hitlTimeoutSeconds . 'S'));

        while (true) {
            ($this->sleepMs)($this->hitlPollIntervalMs);

            $fresh = $this->runRepository->find($runId);
            if ($fresh === null) {
                // Row vanished — treat as a timeout fail-closed.
                return self::HITL_TIMEOUT;
            }

            $status = $fresh->getStatus();
            if ($status === RunStatus::Running) {
                // Approval granted by the API endpoint.
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ApprovalGranted,
                    success: true,
                    toolName: $tool->name,
                    toolResultSummary: sprintf('approval granted (call_id=%s)', $callId),
                );

                return self::HITL_GRANTED;
            }

            if ($status === RunStatus::Cancelling || $status === RunStatus::Cancelled) {
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ApprovalDenied,
                    success: false,
                    toolName: $tool->name,
                    toolResultSummary: 'cancelled while awaiting approval',
                );
                $this->runRepository->markTerminal(
                    $runId,
                    RunStatus::Cancelled,
                    ($this->now)(),
                    errorCode: 'cancelled',
                    errorMessage: 'Cancelled while awaiting approval.',
                );

                return self::HITL_DENIED_INTERACTIVE;
            }

            if ($status === RunStatus::Failed && $fresh->get('error_code') === 'approval_denied') {
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ApprovalDenied,
                    success: false,
                    toolName: $tool->name,
                    toolResultSummary: 'approval denied',
                );

                return self::HITL_DENIED_INTERACTIVE;
            }

            if (($this->now)() >= $deadline) {
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ApprovalDenied,
                    success: false,
                    toolName: $tool->name,
                    toolResultSummary: 'approval timeout',
                );
                $this->runRepository->markTerminal(
                    $runId,
                    RunStatus::Failed,
                    ($this->now)(),
                    errorCode: 'approval_timeout',
                    errorMessage: sprintf('Interactive approval timed out for "%s".', $tool->name),
                );

                return self::HITL_TIMEOUT;
            }
        }
    }

    /**
     * Reload the run row and check for cancellation.
     *
     * If the row is in `Cancelling`, append the `cancelled` error audit
     * row, transition to `Cancelled`, and return true.
     */
    private function checkCancellation(string $runId, int $iteration): bool
    {
        $fresh = $this->runRepository->find($runId);
        if ($fresh === null) {
            return false;
        }
        if ($fresh->getStatus() !== RunStatus::Cancelling) {
            return false;
        }

        $this->appendError(
            $runId,
            $iteration,
            'cancelled',
            'Run cancelled by initiator.',
        );
        $this->runRepository->markTerminal(
            $runId,
            RunStatus::Cancelled,
            ($this->now)(),
            errorCode: 'cancelled',
            errorMessage: 'Cancelled.',
        );

        return true;
    }

    /**
     * Run provider->sendMessage with up to 3 retries on transient
     * failures. Each attempt — successful or not — emits exactly one
     * `provider_call` audit row (FR-025).
     *
     * Returns `null` on retry exhaustion (terminal status already
     * written).
     */
    private function callProviderWithRetry(
        ProviderInterface $provider,
        MessageRequest $request,
        string $runId,
        int $iteration,
    ): ?MessageResponse {
        $attempts = 0;
        $lastError = null;
        $lastErrorIsRateLimit = false;

        while ($attempts < self::PROVIDER_MAX_RETRIES) {
            $attempts++;
            $started = microtime(true);

            try {
                $response = $provider->sendMessage($request);
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ProviderCall,
                    success: true,
                    toolResultSummary: sprintf('attempt %d', $attempts),
                    durationMs: self::msSince($started),
                );

                return $response;
            } catch (RateLimitException $e) {
                // 429 — retryable with backoff
                $lastError = $e;
                $lastErrorIsRateLimit = true;
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ProviderCall,
                    success: false,
                    toolResultSummary: sprintf('attempt %d: rate-limited (%s)', $attempts, $e->getMessage()),
                    durationMs: self::msSince($started),
                );
            } catch (TransportException $e) {
                // 5xx / network — retryable, same budget
                $lastError = $e;
                $lastErrorIsRateLimit = false;
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ProviderCall,
                    success: false,
                    toolResultSummary: sprintf('attempt %d: transport error (%s)', $attempts, $e->getMessage()),
                    durationMs: self::msSince($started),
                );
            } catch (ClientErrorException $e) {
                // 4xx non-429 — non-retryable, re-throw immediately
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ProviderCall,
                    success: false,
                    toolResultSummary: sprintf('attempt %d: client error (non-retryable): %s', $attempts, $e->getMessage()),
                    durationMs: self::msSince($started),
                );
                throw $e;
            } catch (\Throwable $e) {
                // Retry semantics: RateLimitException (429) and TransportException (5xx/network)
                // are retried. ClientErrorException (4xx non-429) and all other exceptions re-throw
                // immediately without consuming retry budget. See FR-003.
                $this->appendAudit(
                    $runId,
                    $iteration,
                    EventType::ProviderCall,
                    success: false,
                    toolResultSummary: sprintf('attempt %d: %s (%s)', $attempts, $e::class, $e->getMessage()),
                    durationMs: self::msSince($started),
                );
                throw $e;
            }

            if ($attempts < self::PROVIDER_MAX_RETRIES) {
                $backoffMs = min(
                    self::PROVIDER_BACKOFF_BASE_MS * (2 ** ($attempts - 1)),
                    self::PROVIDER_BACKOFF_CAP_MS,
                );
                ($this->sleepMs)($backoffMs);
            }
        }

        $errorCode = $lastErrorIsRateLimit ? 'provider_rate_limited' : 'provider_unavailable';
        $errorMessage = sprintf('%s: %s', $errorCode, $lastError->getMessage());

        $this->appendError($runId, $iteration, $errorCode, $errorMessage);
        $this->runRepository->markTerminal(
            $runId,
            RunStatus::Failed,
            ($this->now)(),
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function appendError(
        string $runId,
        int $iteration,
        string $code,
        string $message,
        ?string $toolName = null,
        array $arguments = [],
    ): void {
        $this->appendAudit(
            $runId,
            $iteration,
            EventType::Error,
            success: false,
            toolName: $toolName,
            toolArgumentsJson: $arguments === [] ? null : json_encode($arguments, JSON_THROW_ON_ERROR),
            toolResultSummary: sprintf('%s: %s', $code, $message),
        );
    }

    /**
     * Persist a single audit row through the entity-storage invariant.
     */
    private function appendAudit(
        string $runId,
        int $iteration,
        EventType $eventType,
        bool $success,
        ?string $toolName = null,
        ?string $toolArgumentsJson = null,
        ?string $toolResultSummary = null,
        ?int $durationMs = null,
    ): void {
        try {
            $entry = AgentAuditLog::for(
                id: $this->uuidV4(),
                runId: $runId,
                iteration: $iteration,
                eventType: $eventType,
                occurredAt: ($this->now)(),
                success: $success,
                toolName: $toolName,
                toolArgumentsJson: $toolArgumentsJson,
                toolResultSummary: $toolResultSummary,
                durationMs: $durationMs,
            );
            $this->auditRepository->append($entry);
        } catch (\Throwable $e) {
            // Audit-log failures must not crash the executor. Per spec
            // "best-effort side effects", we surface them via the logger
            // and keep the run going.
            $this->logger->error(sprintf(
                'AgentExecutor: failed to append audit row (event=%s, run=%s): %s',
                $eventType->value,
                $runId,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Append a chunk to the in-memory transcript, enforcing the
     * `config.ai.transcript_max_bytes` cap (NFR-007). Once the cap is
     * reached the marker `[truncated]` is appended exactly once and
     * subsequent chunks are dropped.
     */
    private function appendTranscript(string $transcript, string $chunk): string
    {
        if (str_ends_with($transcript, '[truncated]')) {
            return $transcript;
        }

        $next = $transcript === '' ? $chunk : $transcript . "\n" . $chunk;
        if (strlen($next) <= $this->transcriptMaxBytes) {
            return $next;
        }

        return rtrim(substr($transcript, 0, $this->transcriptMaxBytes), "\n") . "\n[truncated]";
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function msSince(float $startedMicro): int
    {
        return (int) round((microtime(true) - $startedMicro) * 1000);
    }

    private static function toolResultToText(AgentToolResult $result): string
    {
        foreach ($result->content as $block) {
            if ($block['type'] === 'text' && isset($block['text'])) {
                return $block['text'];
            }
        }

        return json_encode($result->content, JSON_THROW_ON_ERROR);
    }

    private static function extractText(MessageResponse $response): string
    {
        $text = '';
        foreach ($response->content as $block) {
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        return $text;
    }

    /**
     * Dispatch an event via the injected dispatcher, swallowing any listener
     * exception so that observability faults never abort the agent run.
     *
     * Constitution gotcha: "Best-effort side effects" — event listeners for
     * non-critical operations must wrap in try-catch and log.
     */
    private function dispatchSafely(object $event): void
    {
        if ($this->eventDispatcher === null) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'AgentExecutor: event dispatch failed for %s: %s',
                $event::class,
                $e->getMessage(),
            ));
        }
    }
}
