<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;

/**
 * Simple in-memory registry of permissions.
 *
 * {@see self::fromProviders()} composes the one boot-time catalogue the kernel
 * binds as {@see PermissionHandlerInterface} (#2788 G1): the compiled
 * manifest's `extra.waaseyaa.permissions` entries plus every provider
 * implementing {@see ProvidesPermissionsInterface}, mirroring
 * `Waaseyaa\User\RoleRepository::fromProviders()`.
 *
 * @api
 */
final class PermissionHandler implements PermissionHandlerInterface
{
    /**
     * @var array<string, array{title: string, description: string}>
     */
    private array $permissions = [];

    /**
     * Build the shared catalogue from declared manifest entries and every
     * provider implementing {@see ProvidesPermissionsInterface}.
     *
     * Deterministic and fail-closed: a provider re-declaring an id another
     * provider or the manifest already declares, or declaring an empty id,
     * is a `\LogicException` — authorization-bearing definitions have one
     * owner rather than depending on provider order.
     *
     * @param iterable<object> $providers Service-provider instances; only those
     *                                    implementing {@see ProvidesPermissionsInterface}
     *                                    are consulted.
     * @param array<string, array{title: string, description?: string}> $declared
     *                                    Manifest-declared entries (`extra.waaseyaa.permissions`).
     */
    public static function fromProviders(iterable $providers, array $declared = []): self
    {
        $handler = new self();
        $owners = [];
        foreach ($declared as $id => $definition) {
            $handler->addOwned($id, $definition, 'extra.waaseyaa.permissions', $owners);
        }

        foreach ($providers as $provider) {
            if (!$provider instanceof ProvidesPermissionsInterface) {
                continue;
            }
            foreach ($provider->permissions() as $id => $definition) {
                $handler->addOwned($id, $definition, $provider::class, $owners);
            }
        }

        return $handler;
    }

    /**
     * @param array{title: string, description?: string} $definition
     * @param array<string, string> $owners id => owner, mutated
     */
    private function addOwned(int|string $id, array $definition, string $owner, array &$owners): void
    {
        // A numeric-string key arrives as int from PHP array semantics.
        $id = (string) $id;
        if ($id === '') {
            throw new \LogicException(sprintf('%s declares an empty permission id.', $owner));
        }
        if (isset($owners[$id])) {
            throw new \LogicException(sprintf(
                'Permission "%s" is declared more than once (%s and %s); retain exactly one owner.',
                $id,
                $owners[$id],
                $owner,
            ));
        }
        $owners[$id] = $owner;
        $this->registerPermission($id, $definition['title'], $definition['description'] ?? '');
    }

    /**
     * Register a new permission.
     *
     * @param string $id          Machine name (e.g. 'create article').
     * @param string $title       Human-readable title.
     * @param string $description Optional description.
     */
    public function registerPermission(string $id, string $title, string $description = ''): void
    {
        $this->permissions[$id] = [
            'title' => $title,
            'description' => $description,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * {@inheritdoc}
     */
    public function hasPermission(string $permission): bool
    {
        return isset($this->permissions[$permission]);
    }
}
