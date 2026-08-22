<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

/**
 * The framework-owned floor of entity types that are never published to a
 * crawler-facing inventory.
 *
 * Applied UNCONDITIONALLY, before any {@see CrawlEligibilityPolicyInterface}. An
 * application policy narrows the eligible type set; it can never widen it, so
 * returning true for a type listed here does not re-enable it. That one-way
 * direction is what lets an application delete its own copy of this list instead
 * of maintaining a second, drifting one.
 *
 * The entries are structural rather than a matter of taste: identity and routing
 * plumbing (`user`, `path_alias`, `relationship`, `file`, `menu_link_content`,
 * `menu`, `config`, `crop`, `workflow`) has no public page to advertise, and the
 * genealogy pair carries a REQUIRED free-text `display_name` that in practice
 * names living people with no living/deceased axis to gate per row. Excluding the
 * genealogy types wholesale is defence in depth alongside
 * `GenealogyContentAccessPolicy`, which the access-aware enumeration already
 * honours; skipping the type avoids a per-row access probe entirely.
 *
 * @api
 */
final class NonPublicEntityTypes
{
    /** @var list<string> */
    private const array DEFAULTS = [
        'user',
        'path_alias',
        'relationship',
        'file',
        'menu_link_content',
        'menu',
        'config',
        'crop',
        'workflow',
        'genealogy_family',
        'genealogy_event',
    ];

    private function __construct() {}

    /** @return list<string> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** Is this entity type excluded by the framework floor? */
    public static function excludes(string $entityTypeId): bool
    {
        return \in_array($entityTypeId, self::DEFAULTS, true);
    }
}
