<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy;
use Waaseyaa\AI\Agent\AgentDefinitionRegistry;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Entity\AgentAuditLog;
use Waaseyaa\AI\Agent\Enum\EventType;
use Waaseyaa\AI\Agent\Enum\RunStatus;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Security\AgentRunAccountProjection;
use Waaseyaa\AI\Agent\Security\AgentRunAccountProjectionReaderInterface;
use Waaseyaa\AI\Agent\Service\AgentRunDraft;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * HTTP surface for the Agent Executor.
 *
 * Routes (see {@see \Waaseyaa\AI\Agent\Routing\AgentRouteServiceProvider}):
 *
 *  - POST   /api/ai/agent/run                — {@see create()}
 *  - GET    /api/ai/agent/run/{id}           — {@see show()}
 *  - DELETE /api/ai/agent/run/{id}           — {@see cancel()}
 *  - POST   /api/ai/agent/run/{id}/approve   — {@see approve()}
 *
 * Authentication/authorization layers:
 *
 *  - Session/auth middleware sets `_account` on the request (constitution
 *    gotcha — NOT `account`).
 *  - Route options enforce capability (`_authenticated`, `_permission`).
 *  - This controller enforces ownership via {@see AgentRunAccessPolicy}
 *    directly so the response can return JSON-shaped 403 instead of the
 *    middleware's generic deny.
 *
 * Body parsing uses {@see Request::getContent()} so the request can be
 * read multiple times (constitution: `php://input` is single-read).
 *
 * @api
 */
final class AgentRunController
{
    private const STREAM_URL_TEMPLATE = '/broadcast?channels=agent.run.%s';
    private const STATUS_URL_TEMPLATE = '/api/ai/agent/run/%s';
    private const APPROVE_URL_TEMPLATE = '/api/ai/agent/run/%s/approve';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly AgentRunService $runService,
        private readonly AgentRunRepository $runRepository,
        private readonly AgentAuditLogRepository $auditRepository,
        private readonly AgentDefinitionRegistry $definitionRegistry,
        private readonly AgentRunBroadcasterInterface $broadcaster,
        private readonly AgentRunAccessPolicy $accessPolicy,
        private readonly AgentRunRequestValidator $validator,
        private readonly AgentRunAccountProjectionReaderInterface $accountProjectionReader,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * POST /api/ai/agent/run
     *
     * Validate body, build {@see AgentRunDraft}, enqueue, return 202.
     */
    public function create(Request $request): Response
    {
        $account = $this->requireAccount($request);
        if ($account === null) {
            return $this->error(401, 'unauthenticated', 'Authentication is required.');
        }

        try {
            $body = $this->decodeBody($request);
        } catch (\JsonException $e) {
            return $this->error(400, 'malformed_json', 'Body is not valid JSON: ' . $e->getMessage());
        }

        $validationError = $this->validator->validateCreate($body);
        if ($validationError !== null) {
            return $this->errorFromValidation($validationError);
        }

        \assert(\is_array($body));

        $agentId = isset($body['agent_id']) && \is_string($body['agent_id']) ? $body['agent_id'] : null;
        $bundle = isset($body['bundle']) && \is_array($body['bundle']) ? $body['bundle'] : null;

        $agentDefault = null;
        $prompt = '';
        if ($agentId !== null) {
            if (!$this->definitionRegistry->has($agentId)) {
                return $this->error(400, 'unknown_agent', "Unknown agent_id: {$agentId}.");
            }
            $definition = $this->definitionRegistry->get($agentId);
            $agentDefault = $definition->destructiveDefault;
            $prompt = $definition->prompt;
        } else {
            \assert(\is_array($bundle));
            $prompt = isset($bundle['prompt']) && \is_string($bundle['prompt']) ? $bundle['prompt'] : '';
        }

        $hitl = $this->validator->resolveDestructiveApproval($body, $agentDefault);

        $draft = new AgentRunDraft(
            accountId: $account->id(),
            agentDefinitionId: $agentId,
            bundle: $bundle,
            prompt: $prompt,
            destructiveApproval: $hitl,
        );

        try {
            $run = $this->runService->enqueue($draft);
        } catch (\InvalidArgumentException $e) {
            return $this->error(400, 'invalid_request', $e->getMessage());
        }

        $runId = (string) $run->get('id');

        return new JsonResponse(
            [
                'run_id' => $runId,
                'stream_url' => \sprintf(self::STREAM_URL_TEMPLATE, $runId),
                'status_url' => \sprintf(self::STATUS_URL_TEMPLATE, $runId),
                'approve_url' => \sprintf(self::APPROVE_URL_TEMPLATE, $runId),
            ],
            status: 202,
        );
    }

    /**
     * GET /api/ai/agent/run/{id}
     */
    public function show(Request $request, string $id): Response
    {
        $account = $this->requireAccount($request);
        if ($account === null) {
            return $this->error(401, 'unauthenticated', 'Authentication is required.');
        }

        $run = $this->runRepository->find($id);
        if ($run === null) {
            return $this->error(404, 'run_not_found', "Run {$id} not found.");
        }

        $access = $this->accessPolicy->access($run, 'view', $account);
        if (!$access->isAllowed()) {
            return $this->error(403, 'forbidden', 'You do not own this run.');
        }

        return new JsonResponse($this->serialize($this->accountProjectionReader->read($run, $account)), status: 200);
    }

    /**
     * DELETE /api/ai/agent/run/{id}
     */
    public function cancel(Request $request, string $id): Response
    {
        $account = $this->requireAccount($request);
        if ($account === null) {
            return $this->error(401, 'unauthenticated', 'Authentication is required.');
        }

        $run = $this->runRepository->find($id);
        if ($run === null) {
            return $this->error(404, 'run_not_found', "Run {$id} not found.");
        }

        $access = $this->accessPolicy->access($run, 'delete', $account);
        if (!$access->isAllowed()) {
            return $this->error(403, 'forbidden', 'You do not own this run.');
        }

        $now = new \DateTimeImmutable('now');
        $status = $run->getStatus();

        if ($status->isTerminal()) {
            return $this->error(409, 'already_terminal', "Run {$id} is already in a terminal state.");
        }

        if ($status === RunStatus::Cancelling) {
            return new Response('', status: 204);
        }

        $flipped = $this->runRepository->requestCancellation($id, $status, $now);
        if (!$flipped) {
            return $this->error(409, 'run_status_changed', "Run {$id} changed state; retry against the current status.");
        }

        if ($status === RunStatus::Queued) {
            $this->broadcaster->push($id, 'run_cancelled', [
                'cancelled_at' => $now->format(\DATE_ATOM),
            ]);

            return new Response('', status: 204);
        }

        return new Response('', status: 204);
    }

    /**
     * POST /api/ai/agent/run/{id}/approve
     */
    public function approve(Request $request, string $id): Response
    {
        $account = $this->requireAccount($request);
        if ($account === null) {
            return $this->error(401, 'unauthenticated', 'Authentication is required.');
        }

        $run = $this->runRepository->find($id);
        if ($run === null) {
            return $this->error(404, 'run_not_found', "Run {$id} not found.");
        }

        $access = $this->accessPolicy->access($run, 'update', $account);
        if (!$access->isAllowed()) {
            return $this->error(403, 'forbidden', 'You do not own this run.');
        }

        try {
            $body = $this->decodeBody($request);
        } catch (\JsonException $e) {
            return $this->error(400, 'malformed_json', 'Body is not valid JSON: ' . $e->getMessage());
        }

        $validationError = $this->validator->validateApprove($body);
        if ($validationError !== null) {
            return $this->errorFromValidation($validationError);
        }

        \assert(\is_array($body));
        $callId = (string) $body['call_id'];
        $decision = (string) $body['decision'];
        $projection = $this->accountProjectionReader->read($run, $account);

        if ($run->getStatus() !== RunStatus::AwaitingApproval) {
            return $this->error(404, 'not_awaiting_approval', "Run {$id} is not awaiting approval.");
        }

        $pending = $projection->pendingApprovalCallId;
        if (!\is_string($pending) || $pending === '' || $pending !== $callId) {
            return $this->error(409, 'call_id_mismatch', 'call_id does not match the pending approval.');
        }

        $now = new \DateTimeImmutable('now');

        if ($decision === 'approve') {
            if (!$this->runRepository->grantApproval($id, $callId)) {
                return $this->error(409, 'run_status_changed', "Run {$id} changed state; approval was not applied.");
            }

            $this->auditRepository->append(AgentAuditLog::for(
                id: Uuid::v4()->toRfc4122(),
                runId: $id,
                iteration: $projection->toolCallCount,
                eventType: EventType::ApprovalGranted,
                occurredAt: $now,
                success: true,
                toolArgumentsJson: $this->jsonEncode(['call_id' => $callId]),
            ));

            $this->broadcaster->push($id, 'approval_resolved', [
                'call_id' => $callId,
                'decision' => 'approve',
            ]);

            return new Response('', status: 204);
        }

        // Deny: terminal failure with approval_denied.
        $flipped = $this->runRepository->denyApproval($id, $callId, $now);

        if (!$flipped) {
            return $this->error(409, 'run_status_changed', "Run {$id} changed state; denial was not applied.");
        }

        $this->auditRepository->append(AgentAuditLog::for(
            id: Uuid::v4()->toRfc4122(),
            runId: $id,
            iteration: $projection->toolCallCount,
            eventType: EventType::ApprovalDenied,
            occurredAt: $now,
            success: false,
            toolArgumentsJson: $this->jsonEncode(['call_id' => $callId]),
        ));

        $this->broadcaster->push($id, 'approval_resolved', [
            'call_id' => $callId,
            'decision' => 'deny',
        ]);

        return new Response('', status: 204);
    }

    /**
     * Read the immutable authorization principal established by middleware.
     */
    private function requireAccount(Request $request): ?AuthorizationPrincipalInterface
    {
        $account = DecisionAccountResolver::resolve(
            $request->attributes->get('_authorization_principal'),
            $request->attributes->get('_account'),
        );
        if ($account === null) {
            return null;
        }

        if (!$account->isAuthenticated()) {
            return null;
        }

        return $account;
    }

    /**
     * Decode the JSON body. Empty body becomes an empty array.
     *
     * @throws \JsonException When the body is malformed JSON.
     */
    private function decodeBody(Request $request): mixed
    {
        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }

        return \json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize a run row into the OpenAPI `AgentRunStatus` shape.
     *
     * @return array<string, mixed>
     */
    private function serialize(AgentRunAccountProjection $run): array
    {
        $pending = null;
        $pendingCallId = $run->pendingApprovalCallId;
        if ($run->status === RunStatus::AwaitingApproval->value
            && \is_string($pendingCallId) && $pendingCallId !== '') {
            $pending = [
                'call_id' => $pendingCallId,
                'tool_name' => '',
                'arguments' => new \stdClass(),
                'expires_at' => $run->approvalExpiresAt ?? '',
            ];
        }

        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'agent_id' => $run->agentDefinitionId,
            'prompt' => $run->prompt,
            'response' => $run->response,
            'transcript' => $run->transcript,
            'truncated' => $run->transcriptTruncated,
            'token_usage_in' => $run->tokenUsageIn,
            'token_usage_out' => $run->tokenUsageOut,
            'cost_cents' => $run->costCents,
            'tool_call_count' => $run->toolCallCount,
            'destructive_approval' => $run->destructiveApproval,
            'pending_approval' => $pending,
            'queued_at' => $run->queuedAt,
            'started_at' => $run->startedAt,
            'finished_at' => $run->finishedAt,
            'error_code' => $run->errorCode,
            'error_message' => $run->errorMessage,
        ];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            [
                'error_code' => $code,
                'error_message' => $message,
            ],
            status: $status,
        );
    }

    /**
     * @param array{error_code: string, error_message: string, details: array{field_errors: list<array{field: string, message: string}>}} $validation
     */
    private function errorFromValidation(array $validation): JsonResponse
    {
        return new JsonResponse(
            [
                'error_code' => $validation['error_code'],
                'error_message' => $validation['error_message'],
                'details' => $validation['details'],
            ],
            status: 400,
        );
    }

    /**
     * Round-trip-safe JSON encode (paired with JSON_THROW_ON_ERROR on decode).
     */
    private function jsonEncode(mixed $value): string
    {
        try {
            return \json_encode($value, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('AgentRunController: json_encode failed: ' . $e->getMessage());
            return '{}';
        }
    }
}
