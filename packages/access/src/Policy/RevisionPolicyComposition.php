<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\TranslatableInterface;

/**
 * Two-axis access policy composition for revisionable + translatable entities.
 *
 * Resolves spec §9 Q7 (M-004): the `view_revision` and `translate` operations
 * are NOT promoted to a new top-level operation (no `view_translation_revision`
 * — FR-023). Instead, this stateless helper routes those operations to the
 * **translation instance** so policies can introspect `activeLangcode()` to
 * make per-language decisions. The helper also accepts an optional
 * `?RevisionableEntityInterface $revision` argument, giving policies access to
 * historical revision metadata (`revisionId()`, `revisionMetadata()`) without
 * a second storage round-trip.
 *
 * ## Contract refs
 *
 * - `kitty-specs/entity-storage-translatable-revisions-01KRCDEE/contracts/access-policy-revision.md`
 * - FR-020 — `view_revision` and `translate` apply to the translation instance.
 * - FR-021 — missing `view_revision` falls back to `view` (ADR 016 FR-040).
 * - FR-022 — missing `translate` falls back to `edit` (ADR 017).
 * - FR-023 — no new `view_translation_revision` operation is introduced.
 *
 * ## Composition vs interface extension
 *
 * `AccessPolicyInterface` itself is not modified by WP05 — extending a stable
 * interface lands at mission close (WP08, per charter §5.3). This helper lets
 * callers obtain the two-axis behaviour today against the existing
 * three-parameter `access(EntityInterface, string, AccountInterface)` signature
 * and is the canonical path for revision/translate gating until the interface
 * extension lands.
 *
 * ## Translation routing
 *
 * For `view_revision` / `translate`:
 *
 *  - If `$revision` is supplied and implements `TranslatableInterface`, the
 *    helper calls `$entity->getTranslation($revision->activeLangcode())` and
 *    invokes the policy with the resulting translation instance.
 *  - Otherwise, the helper invokes the policy with `$entity` as-is (which may
 *    itself already be a translation instance produced by the caller).
 *
 * For `view` / `update` / `delete` the helper is a pass-through: it does not
 * mutate the active langcode and does not consult the revision argument.
 *
 * ## Fallback semantics
 *
 *  - `view_revision` → `view` when the policy returns `Neutral` (FR-021).
 *  - `translate` → `edit` when the policy returns `Neutral` (FR-022).
 *
 * Fallbacks are only triggered for `Neutral`. Explicit `Forbidden`,
 * `Unauthenticated`, and `Allowed` results are honoured as-is — a policy that
 * explicitly forbids `translate` does NOT fall through to `edit`.
 *
 * Note: the existing runtime in `EntityAccessHandler` falls `translate` back
 * to `update`. The contract-level fallback for *policy implementations* is
 * `edit`. This helper implements the contract surface; consumers wiring it
 * into `EntityAccessHandler` should be aware of the asymmetry.
 *
 * @api
 */
final readonly class RevisionPolicyComposition
{
    /**
     * Recognised revision-aware operations routed through translation resolution.
     */
    public const string OPERATION_VIEW_REVISION = 'view_revision';

    public const string OPERATION_TRANSLATE = 'translate';

    /**
     * Base operations used for `Neutral` fallback resolution.
     */
    public const string FALLBACK_VIEW_REVISION = 'view';

    public const string FALLBACK_TRANSLATE = 'edit';

    /**
     * Stateless utility; no construction-time configuration is required.
     */
    public function __construct() {}

    /**
     * Compose a two-axis access decision for an entity + optional revision.
     *
     * @param AccessPolicyInterface             $policy    The access policy to consult.
     * @param EntityInterface                   $entity    The entity being accessed. For
     *                                                     two-axis entities this is typically
     *                                                     a translation instance.
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account Immutable principal.
     * @param string                            $operation The operation, e.g. `view`,
     *                                                     `edit`, `delete`, `translate`,
     *                                                     `view_revision`.
     * @param RevisionableEntityInterface|null  $revision  Optional historical revision the
     *                                                     decision should consider. When the
     *                                                     revision itself implements
     *                                                     `TranslatableInterface`, its
     *                                                     `activeLangcode()` selects the
     *                                                     translation instance passed to the
     *                                                     policy.
     */
    #[\NoDiscard('ignoring an access decision is a security bug')]
    public function composeAccess(
        AccessPolicyInterface $policy,
        EntityInterface $entity,
        AccountInterface $account,
        string $operation,
        ?RevisionableEntityInterface $revision = null,
    ): AccessResult {
        $target = $this->resolveTarget($entity, $operation, $revision);

        $primary = $policy->access($target, $operation, $account);

        if (!$primary->isNeutral()) {
            return $primary;
        }

        $fallback = $this->fallbackOperation($operation);

        if ($fallback === null) {
            return $primary;
        }

        return $policy->access($target, $fallback, $account);
    }

    /**
     * Resolve the translation instance that should be passed to the policy.
     *
     * Per spec §9 Q7 (FR-020), `view_revision` and `translate` operate on the
     * translation instance. For non-translatable entities this is a no-op.
     */
    private function resolveTarget(
        EntityInterface $entity,
        string $operation,
        ?RevisionableEntityInterface $revision,
    ): EntityInterface {
        if (!$entity instanceof TranslatableInterface) {
            return $entity;
        }

        if (!$this->routesThroughTranslation($operation)) {
            return $entity;
        }

        if ($revision instanceof TranslatableInterface) {
            $langcode = $revision->activeLangcode();

            if ($langcode !== $entity->activeLangcode() && $entity->hasTranslation($langcode)) {
                return $entity->getTranslation($langcode);
            }
        }

        return $entity;
    }

    /**
     * Whether the operation should resolve to the translation instance.
     */
    private function routesThroughTranslation(string $operation): bool
    {
        return $operation === self::OPERATION_VIEW_REVISION
            || $operation === self::OPERATION_TRANSLATE;
    }

    /**
     * Resolve the fallback operation for a `Neutral` result, or `null` when
     * the operation has no contract-level fallback.
     */
    private function fallbackOperation(string $operation): ?string
    {
        return match ($operation) {
            self::OPERATION_VIEW_REVISION => self::FALLBACK_VIEW_REVISION,
            self::OPERATION_TRANSLATE     => self::FALLBACK_TRANSLATE,
            default                       => null,
        };
    }
}
