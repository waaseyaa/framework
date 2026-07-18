<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Write;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValueComparator;

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
 * **Echo-tolerant rejection on update() (PR-4 rework, Drupal JSON:API
 * parity):** {@see self::refusedKeys()} above is a hard, unconditional
 * refusal — correct for `store()` (create never has a stored value to echo)
 * but wrong for `update()`: `ResourceSerializer` emits `revision_id`/
 * `published_revision_id` (and `langcode` on translatable types) as ordinary
 * READ attributes (FR-008, `docs/specs/api-layer.md`), and a read-modify-write
 * client — the admin SPA's `SchemaForm.vue` submits the FULL loaded
 * attribute object on save — echoes them straight back on every PATCH. A
 * hard refuse there 422s every ordinary edit through the admin UI.
 * {@see self::evaluateForUpdate()} is the echo-tolerant variant used by
 * `update()` call sites: for the identity/bookkeeping set only, a submitted
 * key is refused only when its value DIFFERS (type-lenient comparison) from
 * the target entity's current stored value; a pure echo passes but is
 * reported separately so the caller can strip it before applying anything
 * (see that method's docblock for the full contract). The undeclared-field
 * branch stays hard-refused either way.
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
        [$identitySet, $writableKeys, $fieldDefinitions] = self::resolveSets($definition, $bundle, $entityTypeManager);

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

        return self::sortedUnique($refused);
    }

    /**
     * Echo-tolerant companion to {@see self::refusedKeys()}, used ONLY on
     * update() (never create — there is no stored value to echo against, and
     * the create surface does not round-trip a prior read).
     *
     * CW-v1 option-1 PR-4 rework, Drupal JSON:API parity: a read-modify-write
     * client (the admin SPA's `SchemaForm.vue` submits the FULL loaded
     * attribute object on save) echoes every attribute it read, including the
     * identity/bookkeeping columns FR-008 documents as load-bearing READ
     * attributes (`docs/specs/api-layer.md`). Hard-refusing every one of
     * those on every ordinary edit 422s the admin UI's own round trip. The
     * fix: for the identity/bookkeeping set (LITERAL_FLOOR ∪ refused-kind
     * columns) ONLY, a submitted key is refused ONLY when its value DIFFERS
     * from `$currentValues`' stored value for that key — a pure echo passes.
     * A passing echo is reported in `$echoedKeys`, NOT applied: callers MUST
     * strip every `$echoedKeys` member from the payload before both the
     * field-access loop and the apply loop (belt — even an allowed echo must
     * never reach `$entity->set()`, so a stale in-memory pointer read before
     * a concurrent transition can never be written back over the real
     * current value).
     *
     * Undeclared/unknown fields are NEVER echo-tolerant — that branch is
     * unchanged from {@see self::refusedKeys()}: hard refuse regardless of
     * whether the submitted value happens to match `$currentValues`.
     *
     * Value comparison is type-lenient: `(string)`-normalized so a
     * JSON-decoded int and a string-hydrated storage column compare equal.
     * `null` and absent (`!array_key_exists`) both count as "stored null";
     * a submitted `null` is an echo only when the stored value is also
     * null/absent — never against a non-null stored value.
     *
     * @param array<int|string, mixed> $attributes    submitted payload (key => value, the caller's `data.attributes`);
     *                                                 an int key (see {@see self::refusedKeys()}'s own docblock for why) is skipped
     * @param array<string, mixed>     $currentValues the target entity's current stored values ({@see \Waaseyaa\Entity\EntityInterface::toArray()})
     */
    public static function evaluateForUpdate(
        EntityTypeInterface $definition,
        string $bundle,
        array $attributes,
        EntityTypeManagerInterface $entityTypeManager,
        array $currentValues,
    ): EntityWritePayloadGuardResult {
        [$identitySet, $writableKeys, $fieldDefinitions] = self::resolveSets($definition, $bundle, $entityTypeManager);
        $identitySet = self::updateIdentitySet($definition, $identitySet);

        $refused = [];
        $echoed = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $identitySet, true)) {
                if (self::isEcho($value, $key, $currentValues)) {
                    $echoed[] = $key;
                } else {
                    $refused[] = $key;
                }
                continue;
            }
            if (isset($fieldDefinitions[$key]) || in_array($key, $writableKeys, true)) {
                continue;
            }
            $refused[] = $key;
        }

        return new EntityWritePayloadGuardResult(self::sortedUnique($refused), self::sortedUnique($echoed));
    }

    /**
     * Entity-backed echo comparison. Framework entities never export a value bag; third-party
     * implementations retain the diagnosed EntityInterface::toArray() compatibility path.
     *
     * @param array<int|string, mixed> $attributes
     */
    public static function evaluateEntityForUpdate(
        EntityTypeInterface $definition,
        string $bundle,
        array $attributes,
        EntityTypeManagerInterface $entityTypeManager,
        EntityInterface $current,
    ): EntityWritePayloadGuardResult {
        if (!$current instanceof EntityBase) {
            return self::evaluateForUpdate($definition, $bundle, $attributes, $entityTypeManager, $current->toArray());
        }

        [$identitySet, $writableKeys, $fieldDefinitions] = self::resolveSets($definition, $bundle, $entityTypeManager);
        $identitySet = self::updateIdentitySet($definition, $identitySet);
        $echoed = new EntityValueComparator()->matchingSubmittedFieldNames($current, $attributes, $identitySet);
        $echoedSet = array_fill_keys($echoed, true);
        $refused = [];
        foreach ($attributes as $key => $_value) {
            if (!is_string($key)) {
                continue;
            }
            if (in_array($key, $identitySet, true)) {
                if (!isset($echoedSet[$key])) {
                    $refused[] = $key;
                }
                continue;
            }
            if (isset($fieldDefinitions[$key]) || in_array($key, $writableKeys, true)) {
                continue;
            }
            $refused[] = $key;
        }

        return new EntityWritePayloadGuardResult(self::sortedUnique($refused), self::sortedUnique($echoed));
    }

    /**
     * Type-lenient echo comparison for one identity/bookkeeping key.
     *
     * @param array<string, mixed> $currentValues
     */
    private static function isEcho(mixed $submitted, string $key, array $currentValues): bool
    {
        $stored = $currentValues[$key] ?? null;

        if ($submitted === null || $stored === null) {
            return $submitted === null && $stored === null;
        }

        // A non-scalar (array/object) submitted value can never echo a
        // stored scalar bookkeeping column — refuse without the string cast
        // (casting an array emits a PHP warning; refusing is the same
        // outcome, minus the log noise).
        if (!\is_scalar($submitted) || !\is_scalar($stored)) {
            return false;
        }

        return (string) $submitted === (string) $stored;
    }

    /**
     * The identity/bookkeeping refusal set, the writable-entity-key set, and
     * the bundle-scoped declared-field map — shared computation for
     * {@see self::refusedKeys()} and {@see self::evaluateForUpdate()}.
     *
     * @return array{0: list<string>, 1: list<string>, 2: array<string, \Waaseyaa\Field\FieldDefinitionInterface>}
     */
    private static function resolveSets(
        EntityTypeInterface $definition,
        string $bundle,
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

        return [$identitySet, $writableKeys, $fieldDefinitions];
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function sortedUnique(array $keys): array
    {
        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Bundle is writable structure on create, but immutable after construction.
     * Update therefore treats an unchanged bundle value like other structural
     * echoes: accept it for read-modify-write clients, then strip it before set().
     *
     * @param list<string> $identitySet
     * @return list<string>
     */
    private static function updateIdentitySet(EntityTypeInterface $definition, array $identitySet): array
    {
        $bundleKey = $definition->getKeys()['bundle'] ?? '';
        if ($bundleKey !== '') {
            $identitySet[] = $bundleKey;
        }

        return array_values(array_unique($identitySet));
    }
}
