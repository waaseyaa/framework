<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\User\RoleRepository;

/**
 * Assign or remove a registered role on a user, stamping that role's
 * registered permissions onto the user's flat permissions array.
 *
 * The framework's user:role command only sets the role string, which is not
 * enough for non-admin roles: User::hasPermission() checks the flat
 * permissions array on the account (only the administrator role is
 * special-cased). This handler resolves the role from the RoleRepository and
 * recomputes the user's permissions as the union of every registry-known role
 * the user holds after the change, so multiple roles compose. It replaces the
 * per-app DashboardAccess::apply pattern.
 *
 * @api
 */
final class UserAssignRoleHandler
{
    public function __construct(
        private readonly RoleRepository $roleRepository,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        /** @var string $userId */
        $userId = $io->argument('user_id');
        /** @var string $roleId */
        $roleId = $io->argument('role');
        $remove = (bool) $io->option('remove');

        $role = $this->roleRepository->get($roleId);
        if ($role === null) {
            $available = $this->roleRepository->ids();
            $io->error(sprintf(
                'Unknown role "%s". Available roles: %s',
                $roleId,
                $available === [] ? '(none registered)' : implode(', ', $available),
            ));

            return 1;
        }

        // C-22 WP3: read/write path now goes through the canonical repository.
        $repository = $this->entityTypeManager->getRepository('user');
        $user = $repository->find($userId);

        if ($user === null) {
            $io->error(sprintf('User with ID "%s" not found.', $userId));

            return 1;
        }

        $currentRoles = $this->internalFields->maintenanceAuthorization($user)->roles;

        if ($remove) {
            $roles = array_values(array_filter(
                $currentRoles,
                static fn(string $r): bool => $r !== $roleId,
            ));
        } else {
            // Replace any sibling registry-known role, but preserve roles that
            // are not in the registry (e.g. ad-hoc or app-specific roles).
            $registryIds = $this->roleRepository->ids();
            $kept = array_values(array_filter(
                $currentRoles,
                static fn(string $r): bool => !in_array($r, $registryIds, true),
            ));
            $roles = array_values(array_unique([...$kept, $roleId]));
        }

        $permissions = $this->computePermissions($roles);

        $user->set('roles', $roles);
        $user->set('permissions', $permissions);
        $repository->save($user);

        if ($remove) {
            $io->writeln(sprintf('Removed role "%s" from user %s.', $roleId, $userId));
        } else {
            $io->writeln(sprintf('Assigned role "%s" to user %s.', $roleId, $userId));
        }

        $io->writeln(sprintf(
            'Permissions now: %s',
            $permissions === [] ? '(none)' : implode(', ', $permissions),
        ));

        return 0;
    }

    /**
     * Union of permissions across every registry-known role the user holds.
     *
     * Roles not present in the registry contribute no permissions; their string
     * membership is preserved on the user but cannot grant permissions here.
     *
     * @param array<int, string> $roleIds
     * @return list<string>
     */
    private function computePermissions(array $roleIds): array
    {
        $permissions = [];

        foreach ($roleIds as $id) {
            $role = $this->roleRepository->get($id);
            if ($role === null) {
                continue;
            }

            foreach ($role->permissions as $permission) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }
}
