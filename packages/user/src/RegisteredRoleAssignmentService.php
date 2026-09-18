<?php

declare(strict_types=1);

namespace Waaseyaa\User;

/**
 * Applies registered role replacement/removal and computes the permission union.
 *
 * Roles unknown to the application registry are preserved during assignment but
 * never contribute permissions. This is the shared authority used by maintenance
 * assignment and safe account provisioning.
 * @api
 */
final readonly class RegisteredRoleAssignmentService
{
    public function __construct(private RoleRepository $roles) {}

    /** @param list<string> $currentRoles */
    public function change(array $currentRoles, string $roleId, bool $remove = false): RegisteredRoleAssignment
    {
        if ($this->roles->get($roleId) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown registered role "%s".', $roleId));
        }

        if ($remove) {
            $nextRoles = array_values(array_filter(
                $currentRoles,
                static fn(string $current): bool => $current !== $roleId,
            ));
        } else {
            $registeredIds = $this->roles->ids();
            $kept = array_values(array_filter(
                $currentRoles,
                static fn(string $current): bool => !in_array($current, $registeredIds, true),
            ));
            $nextRoles = array_values(array_unique([...$kept, $roleId]));
        }

        $permissions = [];
        foreach ($nextRoles as $current) {
            $role = $this->roles->get($current);
            if ($role === null) {
                continue;
            }
            foreach ($role->permissions as $permission) {
                $permissions[$permission] = true;
            }
        }

        return new RegisteredRoleAssignment($nextRoles, array_keys($permissions));
    }
}
