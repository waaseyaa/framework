<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Serializer;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;

/**
 * Applies FieldAccessPolicyInterface enforcement to MCP entity responses.
 *
 * JSON:API omits forbidden fields from the response (absent = denied).
 * MCP preserves field presence for audit lineage: forbidden fields are
 * replaced by the canonical redaction marker so callers know something
 * was withheld, rather than silently receiving a smaller payload.
 *
 * Redaction marker shape (FR-006, C-003):
 *   ['accessRestricted' => true, 'reason' => 'field_forbidden_for_account']
 *
 * Open-by-default semantics (FR-005): Neutral and Allowed results leave the
 * field value as-is. Only an explicit Forbidden result triggers redaction.
 *
 * @api
 */
final class McpEntityFieldFilter
{
    /**
     * Canonical redaction marker — uniqueness guaranteed by C-003.
     *
     * @var array{accessRestricted: true, reason: string}
     */
    public const array REDACTION_MARKER = [
        'accessRestricted' => true,
        'reason' => 'field_forbidden_for_account',
    ];

    public function __construct(
        private readonly EntityAccessHandler $accessHandler,
    ) {}

    /**
     * Apply field-level access enforcement to a serialized MCP entity array.
     *
     * Iterates the `attributes` key of $serializedEntity. For each field
     * where the account's view access returns Forbidden, the value is replaced
     * with {@see REDACTION_MARKER}. Neutral and Allowed values are left intact.
     *
     * @param array<string, mixed> $serializedEntity The already-serialized entity array (MCP shape).
     *                                               Must contain an 'attributes' key.
     * @param EntityInterface      $entity           The entity being serialized.
     * @param AccountInterface     $account          The account requesting access.
     * @return array<string, mixed> The entity array with forbidden fields replaced by the redaction marker.
     */
    public function applyTo(
        array $serializedEntity,
        EntityInterface $entity,
        AccountInterface $account,
    ): array {
        if (!isset($serializedEntity['attributes']) || !\is_array($serializedEntity['attributes'])) {
            return $serializedEntity;
        }

        $attributes = $serializedEntity['attributes'];
        $redacted = [];

        foreach ($attributes as $fieldName => $value) {
            $fieldAccess = $this->accessHandler->checkFieldAccess(
                $entity,
                (string) $fieldName,
                'view',
                $account,
            );

            // Open-by-default: only Forbidden triggers redaction (FR-005).
            $redacted[$fieldName] = $fieldAccess->isForbidden()
                ? self::REDACTION_MARKER
                : $value;
        }

        $serializedEntity['attributes'] = $redacted;

        return $serializedEntity;
    }
}
