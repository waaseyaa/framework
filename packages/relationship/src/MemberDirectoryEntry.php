<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

/**
 * Fixed, purpose-scoped projection of one active group member.
 *
 * The directory boundary exposes no raw entity, field bag, relationship
 * metadata, or account fields beyond the structural user id and display name.
 *
 * @api
 */
final readonly class MemberDirectoryEntry
{
    public function __construct(
        public string $userId,
        public string $displayName,
    ) {}
}
