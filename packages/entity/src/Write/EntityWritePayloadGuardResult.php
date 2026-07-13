<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Write;

/**
 * Result of {@see EntityWritePayloadGuard::evaluateForUpdate()}: the
 * echo-tolerant companion to {@see EntityWritePayloadGuard::refusedKeys()}'s
 * plain `list<string>` return.
 *
 * CW-v1 option-1 PR-4 rework (Drupal JSON:API parity — echo-tolerant
 * rejection): a read-modify-write client (the admin SPA's `SchemaForm.vue`,
 * any JSON:API client that GETs then PATCHes the full attribute set back)
 * echoes every loaded attribute, including the identity/bookkeeping columns
 * `docs/specs/api-layer.md` documents as load-bearing READ attributes
 * (FR-008: `revision_id` in particular). A pure echo (submitted value equals
 * the entity's current stored value, compared type-leniently) must not be
 * refused — only a genuinely DIFFERENT value is (the security core, findings
 * #1/#2). `$echoedKeys` is the set of identity/bookkeeping keys that passed
 * ONLY because they echoed the stored value; callers MUST strip them from
 * the payload before the apply loop (belt: even an allowed echo must never
 * reach `$entity->set()`, so a stale in-memory pointer can never be written
 * back — see `EntityWritePayloadGuard::evaluateForUpdate()`'s docblock).
 *
 * @api
 */
final class EntityWritePayloadGuardResult
{
    /**
     * @param list<string> $refusedKeys keys that must 422 the request, sorted alphabetically
     * @param list<string> $echoedKeys  identity/bookkeeping keys allowed ONLY as an
     *                                  echo of the stored value — strip before apply, sorted alphabetically
     */
    public function __construct(
        public readonly array $refusedKeys,
        public readonly array $echoedKeys,
    ) {}
}
