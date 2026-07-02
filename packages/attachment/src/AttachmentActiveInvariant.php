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
     * Whether the given attachment's in-memory `is_active` value is active.
     *
     * Strict allow-list — active iff the value is `true`, `1`, or `'1'`:
     * exactly the representations boolean assignment and SQLite/MySQL
     * hydration actually produce. A naive `(bool)` cast would read
     * PHP-truthy garbage (the string `'false'`, stray integers) as active
     * and demote every sibling on a malformed value; the allow-list makes
     * garbage inert instead.
     */
    public static function isActive(Attachment $attachment): bool
    {
        return \in_array($attachment->get('is_active'), [true, 1, '1'], true);
    }

    /**
     * Clears `is_active` on every attachment for the given parent, except
     * $exceptId (when given). $exceptId is null for a not-yet-persisted new
     * attachment — it has no row yet, so every existing sibling is demoted.
     *
     * Stamps `updated_at` (unix timestamp, matching the convention used
     * elsewhere in the framework for raw-SQL writes that bypass
     * EntityRepository — e.g. `media`'s version rows — since there is no
     * injectable clock convention to mirror) on every demoted row so a
     * demotion is visible in the row's own audit trail, not just via
     * `is_active` flipping to 0.
     */
    public static function demoteSiblings(
        DatabaseInterface $database,
        string $parentEntityType,
        string $parentEntityId,
        ?string $exceptId,
    ): void {
        $update = $database->update('attachment')
            ->fields(['is_active' => 0, 'updated_at' => time()])
            ->condition('parent_entity_type', $parentEntityType)
            ->condition('parent_entity_id', $parentEntityId);

        if ($exceptId !== null) {
            $update->condition('id', $exceptId, '<>');
        }

        $update->execute();
    }
}
