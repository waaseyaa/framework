<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment;

use Waaseyaa\Database\DatabaseInterface;

/**
 * Shared "demote siblings first" enforcement for the attachment
 * at-most-one-active invariant.
 *
 * Used by BOTH write surfaces that can set `is_active = 1` on an attachment:
 *
 *   - {@see AttachmentRepository::save()} — the direct-repository path.
 *   - {@see AttachmentActiveGuardListener} — the generic entity-API path
 *     (`getRepository('attachment')->save()`), which bypasses
 *     AttachmentRepository entirely.
 *
 * Both surfaces call the SAME two static methods here so the demote
 * semantics — clear every sibling for the same parent, never reject the
 * incoming save — stay identical to {@see AttachmentRepository::setActive()}
 * regardless of which surface performed the write. Centralizing this avoids
 * two independently-maintained copies of the same UPDATE.
 */
final class AttachmentActiveInvariant
{
    private function __construct() {}

    /**
     * Whether the given attachment's in-memory `is_active` value is truthy,
     * tolerant of the bool/int/numeric-string representations `get()` may
     * return depending on hydration source.
     */
    public static function isActive(Attachment $attachment): bool
    {
        return (bool) $attachment->get('is_active');
    }

    /**
     * Clears `is_active` on every attachment for the given parent, except
     * $exceptId (when given). $exceptId is null for a not-yet-persisted new
     * attachment — it has no row yet, so every existing sibling is demoted.
     */
    public static function demoteSiblings(
        DatabaseInterface $database,
        string $parentEntityType,
        string $parentEntityId,
        ?string $exceptId,
    ): void {
        $update = $database->update('attachment')
            ->fields(['is_active' => 0])
            ->condition('parent_entity_type', $parentEntityType)
            ->condition('parent_entity_id', $parentEntityId);

        if ($exceptId !== null) {
            $update->condition('id', $exceptId, '<>');
        }

        $update->execute();
    }
}
