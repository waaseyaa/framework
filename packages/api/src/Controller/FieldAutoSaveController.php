<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Http\JsonApiResponse;
use Waaseyaa\Api\Sanitizer\RichTextSanitizer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Workflows\Transition\TransitionDeniedException;

/**
 * Per-field auto-save endpoint.
 *
 * PUT {api_segment}/{entityType}/{id}/field/{key}
 * Content-Type: application/json
 *
 * {"value": "<string>"}
 *
 * Status codes per contracts/README.md F3:
 *   200 — success
 *   401 — not authenticated
 *   403 — entity-level or field-level access denied
 *   404 — entity not found, entity type unknown, or field key not registered
 *   415 — wrong Content-Type
 *   422 — body too large, malformed JSON, or missing/non-string value
 *
 * Body-size guard (NFR-002): Content-Length header is checked before the full
 * body is read. When Content-Length is absent (e.g. chunked transfer), the
 * guard falls through to post-read validation — callers in production should
 * always send Content-Length for early rejection.
 * @api
 */
final class FieldAutoSaveController
{
    private readonly RichTextSanitizer $richTextSanitizer;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly FieldDefinitionRegistryInterface $fieldRegistry,
        private readonly int $maxBodyBytes = 65536,
        ?RichTextSanitizer $richTextSanitizer = null,
    ) {
        // PHP 8.4 constructor-default gotcha (see CLAUDE.md): resolve in the
        // body, not as a parameter default, so existing callsites keep working.
        $this->richTextSanitizer = $richTextSanitizer ?? new RichTextSanitizer();
    }

    public function update(Request $request, string $entityType, string $id, string $key): Response
    {
        // 1. Content-type negotiation (415).
        if (!$this->isJsonContentType($request)) {
            return $this->error(415, 'unsupported_media_type', 'Content-Type must be application/json');
        }

        // 2. Body size guard before full read (NFR-002).
        //    Trust Content-Length for fast short-circuit; fall through when absent.
        $contentLengthHeader = $request->headers->get('Content-Length');
        if ($contentLengthHeader !== null) {
            $contentLength = (int) $contentLengthHeader;
            if ($contentLength > $this->maxBodyBytes) {
                return $this->error(
                    422,
                    'payload_too_large',
                    "Body exceeds maximum {$this->maxBodyBytes} bytes",
                );
            }
        }

        // 3. Parse body (422 on malformed or oversize after read).
        $rawBody = $request->getContent();
        if (strlen($rawBody) > $this->maxBodyBytes) {
            return $this->error(
                422,
                'payload_too_large',
                "Body exceeds maximum {$this->maxBodyBytes} bytes",
            );
        }

        try {
            $body = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(422, 'malformed_json', 'Body is not valid JSON');
        }

        if (!is_array($body) || !array_key_exists('value', $body) || !is_string($body['value'])) {
            return $this->error(422, 'malformed_body', 'Body must be {"value": "<string>"}');
        }

        // 4. Load entity type and entity (404 if missing).
        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            return $this->error(404, 'entity_type_not_found', "Unknown entity type '{$entityType}'");
        }

        // C-22 WP3: read/write path now goes through the canonical repository.
        $repository = $this->entityTypeManager->getRepository($entityType);
        $entity = $repository->find($id);
        if ($entity === null) {
            return $this->error(404, 'entity_not_found', "Entity '{$entityType}/{$id}' not found");
        }

        // 5. Validate field key against bundle fields (404 if not registered).
        //    Bundle resolved from the loaded entity, not from the URL.
        $bundle = $entity->bundle();
        $coreFields = $this->fieldRegistry->coreFieldsFor($entityType);
        $bundleFields = $this->fieldRegistry->bundleFieldsFor($entityType, $bundle);
        $allFields = $coreFields + $bundleFields;
        if (!isset($allFields[$key])) {
            return $this->error(
                404,
                'field_not_registered',
                "Field '{$key}' not registered for {$entityType}:{$bundle}",
            );
        }

        // 6. Account from request (set by SessionMiddleware as '_account').
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface) {
            return $this->error(401, 'unauthenticated', 'Authentication required');
        }

        // 7. Entity-level access (403). Entity policy uses isAllowed() — deny on Neutral.
        $entityAccess = $this->accessHandler->check($entity, 'update', $account);
        if (!$entityAccess->isAllowed()) {
            return $this->error(403, 'forbidden', "Update denied for {$entityType}/{$id}");
        }

        // 8. Field-level access (403). Field policy uses isForbidden() — allow on Neutral.
        $fieldAccess = $this->accessHandler->checkFieldAccess($entity, $key, 'edit', $account);
        if ($fieldAccess->isForbidden()) {
            return $this->error(403, 'field_forbidden', "Update denied for field '{$key}'");
        }

        // 9. Persist. The RAW, author-submitted value is what gets stored:
        //    sanitization (below) is a read/response-boundary concern only,
        //    so the stored value stays byte-for-byte as authored (non-lossy).
        $entity->set($key, $body['value']);

        // CW-v1 option-1 (#1920 PR-2, design §3.1 finding A2): WorkflowStateGuard
        // denies from PRE_SAVE inside save() for workflow-bound types — this
        // per-field autosave was previously unguarded here and would surface a
        // denial as an uncaught 500. Same permission->403 / other->422 policy
        // as JsonApiController::workflowTransitionDeniedError().
        try {
            $repository->save($entity);
        } catch (TransitionDeniedException $e) {
            return $this->workflowTransitionDeniedError($e);
        }

        // 10. 200 response per contracts/README.md F3.
        // R13 WP2 (audit A11, SECURITY): this response ECHOES the just-saved
        // value back to the caller. For a text_long ("richtext") field that
        // echo is a third read/serialization boundary (alongside JSON:API's
        // ResourceSerializer and the GraphQL plain-field resolver) that a
        // stored <script>/event-handler payload would otherwise reach
        // unsanitized. $allFields[$key] is guaranteed set (checked in step 5).
        $fieldType = $allFields[$key]->getType();
        $responseValue = RichTextSanitizer::isHtmlFieldType($fieldType)
            ? $this->richTextSanitizer->sanitizeValue($entity->get($key))
            : $entity->get($key);

        return new JsonApiResponse([
            'data' => [
                'id' => (string) $entity->id(),
                'type' => $entityType,
                'attributes' => [$key => $responseValue],
            ],
        ]);
    }

    private function isJsonContentType(Request $request): bool
    {
        $type = strtolower((string) $request->headers->get('Content-Type', ''));

        return str_starts_with($type, 'application/json');
    }

    private function error(int $status, string $code, string $title): JsonApiResponse
    {
        return new JsonApiResponse(
            ['errors' => [['status' => (string) $status, 'code' => $code, 'title' => $title]]],
            $status,
        );
    }

    /**
     * @see \Waaseyaa\Api\JsonApiController::workflowTransitionDeniedError() —
     *   same policy (`permission` -> 403, everything else -> 422), expressed
     *   in this controller's own error-document shape (status/code/title,
     *   not a JsonApiError object) rather than widening that method's
     *   visibility.
     */
    private function workflowTransitionDeniedError(TransitionDeniedException $e): JsonApiResponse
    {
        $status = $e->reason === TransitionDeniedException::REASON_PERMISSION ? 403 : 422;

        return $this->error($status, 'workflow_transition_denied', $e->getMessage());
    }
}
