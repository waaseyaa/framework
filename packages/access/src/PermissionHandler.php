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
    /** The manifest owner name used in catalogue diagnostics. */
    private const string MANIFEST_OWNER = 'extra.waaseyaa.permissions';

    /**
     * @var array<string, array{title: string, description: string}>
     */
    private array $permissions = [];

    /**
     * Build the shared catalogue from declared manifest entries and every
     * provider implementing {@see ProvidesPermissionsInterface}.
     *
     * Deterministic and fail-closed with a `\LogicException` that names the
     * owner (a provider class or `extra.waaseyaa.permissions`): a provider
     * re-declaring an id another provider or the manifest already declares
     * (duplicates are reported before the redeclaration's shape is inspected),
     * an invalid id (empty, padded, containing a control character, or a
     * non-string key), a definition that is not an array, a missing or empty
     * string `title`, a non-string `description`, or an unknown member.
     * Authorization-bearing definitions have one owner and one shape rather
     * than depending on provider order or on PHP's coercion.
     *
     * @param iterable<object> $providers Service-provider instances; only those
     *                                    implementing {@see ProvidesPermissionsInterface}
     *                                    are consulted.
     * @param array<int|string, mixed> $declared Manifest-declared entries
     *                                           (`extra.waaseyaa.permissions`), validated here.
     */
    public static function fromProviders(iterable $providers, array $declared = []): self
    {
        $handler = new self();
        $owners = [];
        foreach ($declared as $id => $definition) {
            $handler->addOwned($id, $definition, self::MANIFEST_OWNER, $owners);
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
     * @param array<string, string> $owners id => owner, mutated
     */
    private function addOwned(int|string $id, mixed $definition, string $owner, array &$owners): void
    {
        // A numeric-string key arrives as int from PHP array semantics; that
        // is never a permission id an author wrote.
        if (!is_string($id) || $id === '' || trim($id) !== $id || preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new \LogicException(sprintf(
                '%s declares an invalid permission id %s: a permission id is a non-empty string with no leading, trailing or control characters.',
                $owner,
                var_export($id, true),
            ));
        }
        if (isset($owners[$id])) {
            throw new \LogicException(sprintf(
                'Permission "%s" is declared more than once (%s and %s); retain exactly one owner.',
                $id,
                $owners[$id],
                $owner,
            ));
        }
        if (!is_array($definition)) {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s must be an array with a "title" and an optional "description"; got %s.',
                $id,
                $owner,
                get_debug_type($definition),
            ));
        }
        $title = $definition['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s must carry a non-empty string "title".',
                $id,
                $owner,
            ));
        }
        $description = $definition['description'] ?? '';
        if (!is_string($description)) {
            throw new \LogicException(sprintf(
                'Permission "%s" declared by %s: "description" must be a string when present; got %s.',
                $id,
                $owner,
                get_debug_type($description),
            ));
        }
        foreach (array_keys($definition) as $member) {
            if ($member !== 'title' && $member !== 'description') {
                throw new \LogicException(sprintf(
                    'Permission "%s" declared by %s carries unknown member "%s"; only "title" and "description" are permitted.',
                    $id,
                    $owner,
                    (string) $member,
                ));
            }
        }

        $owners[$id] = $owner;
        $this->registerPermission($id, $title, $description);
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
