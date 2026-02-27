<?php

declare(strict_types=1);

namespace Aurora\User;

/**
 * Value object representing a user role.
 *
 * Roles group permissions together and can be assigned to users.
 * Each role has a unique machine-name ID, a human-readable label,
 * an array of permission strings, and a weight for ordering.
 */
final readonly class Role
{
    /**
     * @param string   $id          The role machine name (e.g. 'editor').
     * @param string   $label       Human-readable label (e.g. 'Editor').
     * @param string[] $permissions Permission strings granted by this role.
     * @param int      $weight      Weight for ordering roles.
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $permissions = [],
        public int $weight = 0,
    ) {}
}
