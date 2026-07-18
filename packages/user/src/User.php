<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Hydration\HydratableFromStorageInterface;
use Waaseyaa\Entity\Hydration\HydrationContext;

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
#[ContentEntityType(id: 'user', label: 'User', description: 'Manage user accounts, roles, and authentication', api: true)]
#[ContentEntityKeys(id: 'uid', uuid: 'uuid', label: 'name')]
final class User extends ContentEntityBase implements AccountInterface, HydratableFromStorageInterface
{
    /**
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [
        // status stays 0/1 in storage and in get()/validate(); use isActive() for booleans.
        'email_verified' => 'bool',
    ];

    #[Field(type: 'integer', required: false, label: 'User ID', readOnly: true, read: FieldReadLevel::Public)]
    public int $uid = 0;

    #[Field(required: false, label: 'UUID', readOnly: true, read: FieldReadLevel::Public)]
    public string $uuid = '';

    #[Field(required: false, label: 'Username', description: 'Login identifier and account label.', read: FieldReadLevel::Protected)]
    public string $name = '';

    #[Field(required: false, label: 'Password hash', readOnly: true, read: FieldReadLevel::Internal)]
    public string $pass = '';

    /** @var list<string> */
    #[Field(required: false, label: 'Roles', settings: ['subtype' => 'string_list'], read: FieldReadLevel::Internal)]
    public array $roles = [];

    /** @var list<string> */
    #[Field(required: false, label: 'Permissions', settings: ['subtype' => 'string_list'], read: FieldReadLevel::Internal)]
    public array $permissions = [];

    #[Field(type: 'email', label: 'Email address', description: 'The email address of the user.', settings: ['weight' => 5], read: FieldReadLevel::Internal)]
    public ?string $mail = null;

    // required: false (#1655): consumers never supply this at creation (the PHP
    // property default is not consulted by get()/validate()), and legacy rows
    // may hold NULL — a derived NotNull rejected the framework's own
    // smoke-shaped save. The flag was inert before save-time validation went
    // live in alpha.204.
    #[Field(required: false, label: 'Email verified', description: 'Whether the user has verified their email address.', settings: ['weight' => 6], read: FieldReadLevel::Internal)]
    public bool $email_verified = false;

    // Account state is a non-recursive authorization input. Direct release is
    // self/admin only; entity/name policies receive it through the exact V2 map.
    #[Field(type: 'boolean', label: 'Active', description: 'Whether the user account is active.', settings: ['weight' => 10, 'authorizationInput' => true], read: FieldReadLevel::Protected)]
    public bool $status = true;

    // Account chronology is retained for audited administration only.
    #[Field(type: 'integer', label: 'Member since', description: 'The date the user account was created.', settings: ['weight' => 20, 'subtype' => 'timestamp'], read: FieldReadLevel::Internal)]
    public ?int $created = null;

    #[Field(label: 'Two-factor secret', description: 'Base32 TOTP secret. null when 2FA is disabled.', settings: ['weight' => 50, 'internal' => true], read: FieldReadLevel::Internal)]
    public ?string $two_factor_secret = null;

    /** @var list<string>|null */
    #[Field(label: 'Two-factor recovery code hashes', description: 'Argon2id-hashed recovery codes; null when 2FA is disabled.', settings: ['weight' => 51, 'internal' => true], read: FieldReadLevel::Internal)]
    public ?array $two_factor_recovery_codes_hash = null;

    #[Field(type: 'integer', label: 'Two-factor last-used step', description: 'Last consumed TOTP time step; blocks replay of a code within its validity window. null until the first successful TOTP verification.', settings: ['weight' => 52, 'internal' => true], read: FieldReadLevel::Internal)]
    public ?int $two_factor_last_used_step = null;

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param array<string, string> $entityKeys Explicit keys when reconstructing via {@see ContentEntityBase::duplicateInstance()}.
     */
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        // Ensure sensible defaults.
        $hasUid = isset($values['uid']);

        $values += [
            'roles' => [],
            'permissions' => [],
            'status' => 1,
        ];

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);

        // Since id() always returns int (never null), EntityBase::isNew()
        // can never detect "new" via id() === null. Mark explicitly.
        if (!$hasUid) {
            $this->enforceIsNew();
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function make(array $values): self
    {
        return new self($values);
    }

    public static function fromStorage(array $values, HydrationContext $context): static
    {
        return new self(
            values: $values,
            entityTypeId: $context->entityTypeId,
            entityKeys: $context->entityKeys,
            fieldDefinitions: [],
        );
    }

    protected function duplicateInstance(array $values): static
    {
        return new static(
            values: $values,
            entityTypeId: $this->getEntityTypeId(),
            entityKeys: $this->entityKeys,
            fieldDefinitions: $this->getFieldDefinitions(),
        );
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
     * A user is authenticated when it is a persisted record (not new) with a non-zero uid.
     */
    public function isAuthenticated(): bool
    {
        // Authenticated means a persisted user with a real uid — an unsaved
        // (isNew) user, or a uid of 0, is not authenticated.
        return !$this->isNew() && $this->id() > 0;
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
    // Two-factor authentication
    // -----------------------------------------------------------------

    public function getTwoFactorSecret(): ?string
    {
        $value = $this->get('two_factor_secret');

        return is_string($value) ? $value : null;
    }

    public function setTwoFactorSecret(?string $secret): static
    {
        return $this->set('two_factor_secret', $secret);
    }

    /**
     * @return list<string>|null
     */
    public function getTwoFactorRecoveryCodesHash(): ?array
    {
        $value = $this->get('two_factor_recovery_codes_hash');

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    /**
     * @param list<string>|null $hashes
     */
    public function setTwoFactorRecoveryCodesHash(?array $hashes): static
    {
        return $this->set('two_factor_recovery_codes_hash', $hashes);
    }

    /**
     * The last TOTP time step consumed by a successful verification, or null
     * if none yet. Used to reject replay of a code within its validity window.
     */
    public function getTwoFactorLastUsedStep(): ?int
    {
        $value = $this->get('two_factor_last_used_step');

        return is_numeric($value) ? (int) $value : null;
    }

    public function setTwoFactorLastUsedStep(?int $step): static
    {
        return $this->set('two_factor_last_used_step', $step);
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
        return (bool) ($this->get('status') ?? false);
    }

    /**
     * Activate or block the user account.
     */
    public function setActive(bool $active): static
    {
        return $this->set('status', $active ? 1 : 0);
    }

    // -----------------------------------------------------------------
    // Email verification
    // -----------------------------------------------------------------

    /**
     * Whether the user's email address has been verified.
     */
    public function isEmailVerified(): bool
    {
        return (bool) ($this->get('email_verified') ?? false);
    }

    /**
     * Set the email verification status.
     */
    public function setEmailVerified(bool $verified): static
    {
        return $this->set('email_verified', $verified);
    }
}
