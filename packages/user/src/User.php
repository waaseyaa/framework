<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityBase;

/**
 * The User content entity.
 *
 * Represents an authenticated user account in Waaseyaa. Extends
 * ContentEntityBase for field support and implements AccountInterface
 * so that the access system can check permissions against this object.
 *
 * For v0.1.0 the User stores its roles (as string IDs) and a flat
 * permissions array directly. The caller (or a higher-level factory /
 * service) is responsible for populating the permissions from role
 * definitions.
 */
final class User extends ContentEntityBase implements AccountInterface
{
    /**
     * The entity type machine name.
     */
    private const ENTITY_TYPE_ID = 'user';

    /**
     * Entity key mappings for the user entity type.
     */
    private const ENTITY_KEYS = [
        'id' => 'uid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    /**
     * @param array<string, mixed> $values Initial entity values.
     */
    public function __construct(array $values = [])
    {
        // Ensure sensible defaults.
        $hasUid = isset($values['uid']);

        $values += [
            'roles' => [],
            'permissions' => [],
            'status' => 1,
        ];

        parent::__construct($values, self::ENTITY_TYPE_ID, self::ENTITY_KEYS);

        // Since id() always returns int (never null), EntityBase::isNew()
        // can never detect "new" via id() === null. Mark explicitly.
        if (!$hasUid) {
            $this->enforceIsNew();
        }
    }

    // -----------------------------------------------------------------
    // AccountInterface
    // -----------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Returns the numeric user ID. Anonymous user has uid 0.
     */
    public function id(): int
    {
        $raw = parent::id();

        return (int) ($raw ?? 0);
    }

    /**
     * The role ID that grants all permissions by default.
     */
    private const ADMINISTRATOR_ROLE = 'administrator';

    /**
     * {@inheritdoc}
     *
     * Checks whether the given permission exists in the user's
     * flat permissions array. Administrators have all permissions
     * by default.
     */
    public function hasPermission(string $permission): bool
    {
        // Administrators have all permissions.
        if (\in_array(self::ADMINISTRATOR_ROLE, $this->getRoles(), true)) {
            return true;
        }

        $permissions = $this->get('permissions');

        return \is_array($permissions) && \in_array($permission, $permissions, true);
    }

    /**
     * {@inheritdoc}
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = $this->get('roles');

        return \is_array($roles) ? $roles : [];
    }

    /**
     * {@inheritdoc}
     *
     * A user is authenticated when its uid is not 0 (anonymous).
     */
    public function isAuthenticated(): bool
    {
        return $this->id() !== 0;
    }

    // -----------------------------------------------------------------
    // Name helpers
    // -----------------------------------------------------------------

    public function getName(): string
    {
        return (string) ($this->get('name') ?? '');
    }

    public function setName(string $name): static
    {
        return $this->set('name', $name);
    }

    // -----------------------------------------------------------------
    // Email helpers
    // -----------------------------------------------------------------

    public function getEmail(): string
    {
        return (string) ($this->get('mail') ?? '');
    }

    public function setEmail(string $email): static
    {
        return $this->set('mail', $email);
    }

    // -----------------------------------------------------------------
    // Password management
    // -----------------------------------------------------------------

    /**
     * Returns the hashed password.
     */
    public function getPassword(): string
    {
        return (string) ($this->get('pass') ?? '');
    }

    /**
     * Sets the password hash directly (already hashed value).
     */
    public function setPassword(string $hash): static
    {
        return $this->set('pass', $hash);
    }

    /**
     * Hashes a plaintext password and stores it.
     */
    public function setRawPassword(string $plaintext): static
    {
        $hash = password_hash($plaintext, \PASSWORD_DEFAULT);

        return $this->set('pass', $hash);
    }

    /**
     * Verifies a plaintext password against the stored hash.
     */
    public function checkPassword(string $plaintext): bool
    {
        $hash = $this->getPassword();

        if ($hash === '') {
            return false;
        }

        return password_verify($plaintext, $hash);
    }

    // -----------------------------------------------------------------
    // Roles / permissions
    // -----------------------------------------------------------------

    /**
     * Replace all role IDs.
     *
     * @param string[] $roles
     */
    public function setRoles(array $roles): static
    {
        return $this->set('roles', $roles);
    }

    /**
     * Add a single role ID.
     */
    public function addRole(string $roleId): static
    {
        $roles = $this->getRoles();

        if (!\in_array($roleId, $roles, true)) {
            $roles[] = $roleId;
        }

        return $this->set('roles', $roles);
    }

    /**
     * Remove a single role ID.
     */
    public function removeRole(string $roleId): static
    {
        $roles = array_values(array_filter(
            $this->getRoles(),
            static fn(string $r): bool => $r !== $roleId,
        ));

        return $this->set('roles', $roles);
    }

    /**
     * Replace the flat permissions array.
     *
     * @param string[] $permissions
     */
    public function setPermissions(array $permissions): static
    {
        return $this->set('permissions', $permissions);
    }

    // -----------------------------------------------------------------
    // Status (active / blocked)
    // -----------------------------------------------------------------

    /**
     * Whether the user account is active (not blocked).
     */
    public function isActive(): bool
    {
        return (int) ($this->get('status') ?? 0) === 1;
    }

    /**
     * Activate or block the user account.
     */
    public function setActive(bool $active): static
    {
        return $this->set('status', $active ? 1 : 0);
    }
}
