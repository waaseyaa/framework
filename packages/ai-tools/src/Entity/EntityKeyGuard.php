<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Entity;

use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Validation\EntityValidationException;

/**
 * Identity-key write guard for the stock entity agent tools.
 *
 * Computes the per-entity-type refusal set — registered entity-key columns
 * for the kinds `id`, `uuid`, `revision`, `langcode`, `default_langcode`
 * (so renamed columns like `id => nid` are caught under their real name),
 * unioned with the literal names `uuid`, `langcode`, `default_langcode`,
 * `revision_id`, `published_revision_id` (the floor catches translatable
 * schema columns on types that never registered the kind, and — the
 * `revision_id`/`published_revision_id` pair, added by CW-v1 option-1 PR-4 —
 * the pointer/bookkeeping columns findings #1/#2 of
 * `.superpowers/sdd/final-review-findings.md` name directly:
 * `published_revision_id` in particular carries NO entity-key kind on any
 * shipped entity type, so only the literal floor closes it; before this
 * addition it was silently `set()`-able through this tool despite the
 * class-level "identity fields cannot be written through this tool"
 * contract). The `label` and `bundle` kinds are deliberately NEVER refused:
 * label is ordinary content (e.g. `title`) and bundle is create-time
 * structure.
 *
 * Contract: kitty-specs/live-entity-validation-key-protection-01KTWQT3/
 * contracts/tool-refusal.md (FR-005..FR-007, NFR-002/NFR-003).
 */
final class EntityKeyGuard
{
    /**
     * Entity-key kinds whose registered column names are refused.
     *
     * @var list<string>
     */
    private const REFUSED_KINDS = ['id', 'uuid', 'revision', 'langcode', 'default_langcode'];

    /**
     * Literal column names refused even when the kind is unregistered.
     *
     * @var list<string>
     */
    private const LITERAL_FLOOR = ['uuid', 'langcode', 'default_langcode', 'revision_id', 'published_revision_id'];

    private function __construct() {}

    /**
     * Identity keys present in the supplied values payload.
     *
     * Only string keys of $values are considered.
     *
     * @param array<mixed> $values
     *
     * @return list<string> refused key names found in $values, sorted alphabetically
     */
    public static function refusedKeys(EntityTypeInterface $definition, array $values): array
    {
        $refusalSet = self::LITERAL_FLOOR;
        $registeredKeys = $definition->getKeys();
        foreach (self::REFUSED_KINDS as $kind) {
            $column = $registeredKeys[$kind] ?? '';
            if ($column !== '') {
                $refusalSet[] = $column;
            }
        }

        $refused = [];
        foreach (array_keys($values) as $key) {
            if (is_string($key) && in_array($key, $refusalSet, true)) {
                $refused[] = $key;
            }
        }
        $refused = array_values(array_unique($refused));
        sort($refused);

        return $refused;
    }

    /**
     * Whole-write refusal result (contract clause 3): nothing was
     * constructed, nothing was set, nothing was saved.
     *
     * @param list<string> $refused sorted refused key names
     */
    public static function refusalError(string $toolName, array $refused): AgentToolResult
    {
        $message = sprintf(
            '%s: refused identity keys: %s — identity fields cannot be written through this tool',
            $toolName,
            implode(', ', $refused),
        );

        // AgentToolResult::error() is message-only, but the public constructor
        // already supports error results with arbitrary content blocks (it is
        // exactly what error() calls). Attaching the machine-readable payload
        // this way adds no new public API (research D6).
        return new AgentToolResult(
            isError: true,
            content: [
                ['type' => 'text', 'text' => $message],
                ['type' => 'json', 'data' => ['error' => 'identity_keys_refused', 'refused_keys' => $refused]],
            ],
            summary: $message,
        );
    }

    /**
     * Deterministic mapping of a save-time validation failure (contract
     * clause 8): violations sorted by field name, insertion order as the
     * stable tiebreak (NFR-003).
     */
    public static function validationError(string $toolName, EntityValidationException $exception): AgentToolResult
    {
        $violations = [];
        $index = 0;
        foreach ($exception->violations as $violation) {
            $violations[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
                'invalid_value_type' => get_debug_type($violation->getInvalidValue()),
                'index' => $index,
            ];
            ++$index;
        }
        usort(
            $violations,
            static fn(array $a, array $b): int => [$a['field'], $a['index']] <=> [$b['field'], $b['index']],
        );
        $violations = array_map(
            static function (array $violation): array {
                unset($violation['index']);

                return $violation;
            },
            $violations,
        );

        $parts = array_map(
            static fn(array $violation): string => $violation['field'] . ': ' . $violation['message'],
            $violations,
        );
        $message = sprintf('%s: validation failed: %s', $toolName, implode('; ', $parts));

        return new AgentToolResult(
            isError: true,
            content: [
                ['type' => 'text', 'text' => $message],
                ['type' => 'json', 'data' => ['error' => 'validation_failed', 'violations' => $violations]],
            ],
            summary: $message,
        );
    }
}
