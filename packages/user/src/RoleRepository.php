<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;

/**
 * Id-keyed registry of {@see Role} definitions collected from service providers.
 *
 * Roles are discovered by iterating every service provider implementing
 * {@see ProvidesRolesInterface} and flattening their {@see Role} contributions
 * into a single map keyed by role id. This mirrors the discovery pattern used
 * by provider-capability discovery / {@code ListingDiscoverer} for other
 * provider capabilities: filter by {@code instanceof}, then flatten.
 *
 * The repository is the canonical source the user:assign-role command consults
 * to resolve a role id to its registered permissions, so that assigning a role
 * also stamps those permissions onto the user's flat permissions array.
 *
 * @api
 */
final class RoleRepository
{
    /** @var array<string, Role> */
    private array $roles = [];

    /**
     * @param iterable<Role> $roles Initial role definitions, keyed by id on add.
     */
    public function __construct(iterable $roles = [])
    {
        foreach ($roles as $role) {
            $this->add($role);
        }
    }

    /**
     * Build a repository by collecting roles from every provider implementing
     * {@see ProvidesRolesInterface}.
     *
     * Duplicate ids fail closed. Authorization-bearing definitions must have
     * one owner rather than depend on provider order.
     *
     * @param iterable<object> $providers Service-provider instances; only those
     *                                    implementing {@see ProvidesRolesInterface}
     *                                    are consulted.
     */
    public static function fromProviders(iterable $providers): self
    {
        $repository = new self();

        foreach ($providers as $provider) {
            if (!$provider instanceof ProvidesRolesInterface) {
                continue;
            }

            foreach ($provider->roles() as $role) {
                $repository->add($role);
            }
        }

        return $repository;
    }

    /**
     * Register a single role, refusing a duplicate id.
     */
    public function add(Role $role): void
    {
        if (isset($this->roles[$role->id])) {
            throw new \LogicException(sprintf(
                'Role "%s" is registered more than once; retain exactly one provider.',
                $role->id,
            ));
        }
        $this->roles[$role->id] = $role;
    }

    /**
     * Look up a role by its machine-name id, or null when unknown.
     */
    public function get(string $id): ?Role
    {
        return $this->roles[$id] ?? null;
    }

    /**
     * Whether a role with the given id is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->roles[$id]);
    }

    /**
     * All registered roles, keyed by id.
     *
     * @return array<string, Role>
     */
    public function all(): array
    {
        return $this->roles;
    }

    /**
     * The ids of all registered roles, in registration order.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->roles);
    }
}
