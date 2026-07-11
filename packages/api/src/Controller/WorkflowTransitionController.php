<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Http\JsonApiResponse;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;
use Waaseyaa\Workflows\Transition\TransitionService;

/**
 * Workflow transition endpoints (CW-v1 WP-4, docs/specs/content-workflow.md
 * "Integration -> API (WP-4)").
 *
 * GET  {api_segment}/{entityType}/{id}/workflow/transitions
 * POST {api_segment}/{entityType}/{id}/workflow/transition
 *
 * Both endpoints require authentication and are gated in the controller (not
 * via a route `_gate` option) so a view-denied entity can return the SAME
 * canonical 404 document a missing entity does (R8 oracle standard, design
 * decision 2 of the WP-4 plan) — a route-option gate's 403 would break that
 * byte-identity. Follows {@see FieldAutoSaveController}'s idioms: reads
 * `_account` off the request, returns Symfony `Response` objects directly.
 * @api
 */
final class WorkflowTransitionController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?EntityAccessHandler $accessHandler,
        private readonly TransitionService $transitionService,
    ) {}

    /**
     * GET — the transitions `_account` may fire from the entity's current
     * state, plus the current `workflow_state` for the SPA badge. Always
     * 200; an unbound entity type (or one with a workflow but no available
     * transitions) returns an empty `data` array — no buttons is the correct
     * UI, never a 404/422 (design decision 5).
     */
    public function transitions(Request $request, string $entityType, string $id): Response
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->unauthenticated();
        }

        $entity = $this->loadViewableEntity($entityType, $id, $account);
        if ($entity === null) {
            return $this->notFound($entityType, $id);
        }

        $available = $this->transitionService->getAvailableTransitions($entity, $account);

        $data = [];
        foreach ($available as $transition) {
            $data[] = [
                'id' => $transition->id,
                'label' => $transition->label,
                'to' => $transition->to,
            ];
        }

        $state = $entity->get('workflow_state');
        $state = is_string($state) && $state !== '' ? $state : null;

        // Field-level view gate on the surfaced state (PR #1956 reviewer
        // finding): the entity-level view check above only gates `data`
        // (loading the entity + the transition list); ResourceSerializer's
        // canonical read path additionally filters view-forbidden fields,
        // and this endpoint's `meta.workflow_state` was bypassing that.
        // `$this->accessHandler` is guaranteed non-null here — the null case
        // already 404'd inside loadViewableEntity(). Field semantics are
        // "allow unless Forbidden" (Neutral = accessible).
        if ($state !== null && $this->accessHandler !== null
            && $this->accessHandler->checkFieldAccess($entity, 'workflow_state', 'view', $account)->isForbidden()
        ) {
            $state = null;
        }

        return new JsonApiResponse([
            'data' => $data,
            'meta' => ['workflow_state' => $state],
        ]);
    }

    /**
     * POST — fire a transition. Body: `{"transition": "publish"}`.
     *
     * `TransitionDeniedException` mapping is the WP-2 contract, duplicated
     * here in miniature ({@see JsonApiController::workflowTransitionDeniedError()}
     * stays private — this controller carries its own copy of the tiny
     * mapping rather than widening that method's visibility): `permission`
     * -> 403, everything else -> 422, `code: WORKFLOW_TRANSITION_DENIED`,
     * `meta.reason` as the machine-readable discriminator.
     */
    public function transition(Request $request, string $entityType, string $id): Response
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->unauthenticated();
        }

        $entity = $this->loadViewableEntity($entityType, $id, $account);
        if ($entity === null) {
            return $this->notFound($entityType, $id);
        }

        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->errorResponse(JsonApiError::badRequest('Body is not valid JSON.'));
        }

        if (!is_array($body) || !array_key_exists('transition', $body) || !is_string($body['transition']) || $body['transition'] === '') {
            return $this->errorResponse(JsonApiError::badRequest('Body must be {"transition": "<string>"}.'));
        }

        try {
            $result = $this->transitionService->transition($entity, $body['transition'], $account);
        } catch (TransitionDeniedException $e) {
            return $this->errorResponse($this->workflowTransitionDeniedError($e));
        }

        return new JsonApiResponse([
            'data' => [
                'transition' => $result->transitionId,
                'from' => $result->fromState,
                'to' => $result->toState,
            ],
        ]);
    }

    /**
     * Loads the entity by id or uuid (mirrors
     * {@see JsonApiController::loadByIdOrUuid()}) and applies the R8 oracle
     * view-access gate. Returns null for BOTH "does not exist" and
     * "exists but view is denied" — the two callers above turn a null into
     * the identical {@see self::notFound()} document, so the response cannot
     * act as an existence oracle.
     *
     * Fails CLOSED when no access handler is wired: these are
     * workflow-state-revealing surfaces, not generic reads.
     */
    private function loadViewableEntity(string $entityTypeId, string $id, AccountInterface $account): ?EntityInterface
    {
        if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
            return null;
        }

        $repository = $this->entityTypeManager->getRepository($entityTypeId);
        $definition = $this->entityTypeManager->getDefinition($entityTypeId);
        $keys = $definition->getKeys();

        $entity = null;
        if (isset($keys['uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
            $query = $repository->getQuery()->setAccount($account);
            $query->condition($keys['uuid'], $id);
            $ids = $query->execute();
            if ($ids !== []) {
                $entity = $repository->find((string) reset($ids));
            }
        } else {
            $entity = $repository->find($id);
        }

        if ($entity === null) {
            return null;
        }

        if ($this->accessHandler === null) {
            return null;
        }

        $access = $this->accessHandler->check($entity, 'view', $account);

        return $access->isAllowed() ? $entity : null;
    }

    /**
     * Canonical single 404 (byte-identical whether the entity does not exist
     * or `_account` was denied view access — R8 oracle standard).
     */
    private function notFound(string $entityTypeId, string $id): JsonApiResponse
    {
        return $this->errorResponse(
            JsonApiError::notFound("Entity of type '{$entityTypeId}' with ID '{$id}' not found."),
        );
    }

    private function unauthenticated(): JsonApiResponse
    {
        return new JsonApiResponse(
            ['errors' => [['status' => '401', 'code' => 'unauthenticated', 'title' => 'Authentication required']]],
            401,
        );
    }

    /**
     * @see JsonApiController::workflowTransitionDeniedError() — same policy,
     *   duplicated here rather than widening that method's visibility.
     */
    private function workflowTransitionDeniedError(TransitionDeniedException $e): JsonApiError
    {
        $meta = ['reason' => $e->reason];

        return $e->reason === TransitionDeniedException::REASON_PERMISSION
            ? JsonApiError::forbidden($e->getMessage(), code: 'WORKFLOW_TRANSITION_DENIED', meta: $meta)
            : JsonApiError::unprocessable($e->getMessage(), code: 'WORKFLOW_TRANSITION_DENIED', meta: $meta);
    }

    private function errorResponse(JsonApiError $error): JsonApiResponse
    {
        return new JsonApiResponse(['errors' => [$error->toArray()]], (int) $error->status);
    }
}
