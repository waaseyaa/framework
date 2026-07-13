<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Write;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Write-side field allowlist for the HTTP/agent entity-mutation surfaces
 * (JSON:API, admin-surface, GraphQL) — CW-v1 option-1 design §5, closing
 * findings #1/#2 of `.superpowers/sdd/final-review-findings.md`.
 *
 * Root cause: `JsonApiController::store()`/`update()` (and, transitively,
 * `GenericAdminSurfaceHost` and GraphQL's `EntityResolver`, which delegate to
 * or mirror the same apply-every-submitted-attribute shape) applied every
 * submitted attribute with only per-field ACCESS as the gate. Neither
 * `revision_id` nor `published_revision_id` has a declared field or a shipped
 * field-access policy, so an account with plain entity `update` access could
 * move the published pointer (or forge the current-revision id) directly
 * through a PATCH body, bypassing `WorkflowPointerMoveGuard` and every
 * transition permission entirely.
 *
 * Modeled on ai-tools' {@see \Waaseyaa\AI\Tools\Entity\EntityKeyGuard}, with
 * two adaptations for this surface: (1) keyed by PAYLOAD KEY presence, not
 * payload value (the caller already has `array_keys($attributes)`), and (2)
 * checked against the bundle-scoped declared-field set
 * ({@see EntityTypeManagerInterface::resolveFieldDefinitions()} — the exact
 * canonical source the read-path allowlist, `JsonApiController::validateQueryFields()`,
 * already uses) rather than only entity keys, so an ordinary bundle field
 * (e.g. `body`) is writable even though it carries no FieldDefinition at the
 * base entity-type level.
 *
 * A payload key is refused when either:
 *  - it is an identity/bookkeeping column: the entity-key KINDS `id`, `uuid`,
 *    `revision`, `langcode`, `default_langcode` (resolved via
 *    {@see EntityTypeInterface::getKeys()}, so a renamed column — e.g.
 *    `id => nid` — is caught under its real name), unioned with the literal
 *    floor `revision_id`, `published_revision_id`, `uuid`, `langcode`,
 *    `default_langcode` (catches an unregistered kind, and — critically —
 *    `published_revision_id`, which carries NO entity-key kind at all on any
 *    shipped entity type, so only the literal floor closes findings #1/#2) —
 *    refused regardless of field declaration; OR
 *  - it is NOT a declared field (bundle-scoped `resolveFieldDefinitions()`)
 *    and NOT a writable entity key (`label`/`bundle` — ordinary content and
 *    create-time structure respectively, deliberately never refused,
 *    mirroring EntityKeyGuard's docblock).
 *
 * `status`/`workflow_state` are ordinary declared fields on `node`, so they
 * pass this guard untouched; their write is gated by field-level access
 * (`NodeAccessPolicy::PUBLISH_GATED_FIELDS`) exactly as before — this guard
 * does not double-gate them (design §5: "do not double-gate").
 *
 * Deliberately does NOT refuse the `id` kind (unlike EntityKeyGuard): the
 * JSON:API create surface has a pre-existing, tested contract for
 * config-style entities (e.g. `node_type`) where the id key IS the writable
 * machine-name column at create time (`JsonApiController::store()`'s
 * `$usesConfigMachineIds` branch, `JsonApiControllerConfigEntityTest::storePreservesExplicitMachineNameForConfigEntity`).
 * For a numeric/uuid-keyed content entity (e.g. `node`'s `nid`), the id-kind
 * column is simply never a declared field and never `label`/`bundle`, so it
 * is refused anyway via the ordinary declared-field-or-writable-key branch —
 * the same effective protection EntityKeyGuard's explicit `id` refusal gives
 * its own (config-entity-naive) callers, achieved here without special-casing.
 *
 * @api
 */
final class EntityWritePayloadGuard
{
    /**
     * Entity-key kinds whose registered column names are refused regardless
     * of field declaration.
     *
     * @var list<string>
     */
    private const REFUSED_KINDS = ['uuid', 'revision', 'langcode', 'default_langcode'];

    /**
     * Literal column names refused even when the kind is unregistered.
     * `revision_id`/`published_revision_id` are the pointer/bookkeeping
     * columns findings #1/#2 name directly.
     *
     * @var list<string>
     */
    private const LITERAL_FLOOR = ['revision_id', 'published_revision_id', 'uuid', 'langcode', 'default_langcode'];

    /**
     * Entity-key kinds that are NEVER refused: `label` is ordinary content,
     * `bundle` is create-time structure. Mirrors EntityKeyGuard.
     *
     * @var list<string>
     */
    private const WRITABLE_KINDS = ['label', 'bundle'];

    private function __construct() {}

    /**
     * $payloadKeys is nominally `list<string>` (the caller's `array_keys($attributes)`),
     * but `array_keys()` on a JSON-decoded associative array can yield an
     * integer key (e.g. an attribute literally named `"0"` numeric-casts to
     * `int` per PHP array-key coercion) — the `is_string()` guard below is a
     * real defensive check, not dead code, mirroring EntityKeyGuard's own
     * "only string keys are considered" contract.
     *
     * @param list<int|string> $payloadKeys
     *
     * @return list<string> refused key names present in $payloadKeys, sorted alphabetically
     */
    public static function refusedKeys(
        EntityTypeInterface $definition,
        string $bundle,
        array $payloadKeys,
        EntityTypeManagerInterface $entityTypeManager,
    ): array {
        $registeredKeys = $definition->getKeys();

        $identitySet = self::LITERAL_FLOOR;
        foreach (self::REFUSED_KINDS as $kind) {
            $column = $registeredKeys[$kind] ?? '';
            if ($column !== '') {
                $identitySet[] = $column;
            }
        }
        $identitySet = array_values(array_unique($identitySet));

        $writableKeys = [];
        foreach (self::WRITABLE_KINDS as $kind) {
            $column = $registeredKeys[$kind] ?? '';
            if ($column !== '') {
                $writableKeys[] = $column;
            }
        }

        $fieldDefinitions = $entityTypeManager->resolveFieldDefinitions(
            $definition->id(),
            $bundle !== '' ? $bundle : null,
        );

        $refused = [];
        foreach ($payloadKeys as $key) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $identitySet, true)) {
                $refused[] = $key;
                continue;
            }
            if (isset($fieldDefinitions[$key]) || in_array($key, $writableKeys, true)) {
                continue;
            }
            $refused[] = $key;
        }

        $refused = array_values(array_unique($refused));
        sort($refused);

        return $refused;
    }
}
