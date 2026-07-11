<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

/**
 * `relationship_type` values for group edges (CW-v1 WP-3).
 *
 * @see docs/specs/content-workflow.md
 * @api
 */
final class GroupRelationshipTypes
{
    /**
     * Directed: `user`/{uid} → `group`/{gid}.
     */
    public const string MEMBERSHIP = 'group_membership';

    /**
     * Directed: `{entityType}`/{id} → `group`/{gid}.
     */
    public const string CONTENT = 'group_content';
}
