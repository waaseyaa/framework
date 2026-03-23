<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows;

/**
 * Configurable authoring role matrix.
 *
 * Generates bundle-scoped permission sets from role definitions.
 * Applications define their own roles and permission templates;
 * the framework handles bundle expansion.
 *
 * Permission templates use `{bundle}` as a placeholder that gets
 * expanded for each bundle in the matrix.
 *
 * Example role definition:
 *   [
 *       'label' => 'Contributor',
 *       'permissions' => ['access content'],
 *       'bundle_permissions' => [
 *           'create {bundle} content',
 *           'edit own {bundle} content',
 *       ],
 *   ]
 */
final class AuthoringRoleMatrix
{
    /**
     * @param list<string> $bundles Content bundles to expand permissions for
     * @param array<string, array{label: string, permissions?: list<string>, bundle_permissions?: list<string>}> $roles
     *   Role definitions keyed by role ID. Each role has:
     *   - label: Human-readable name
     *   - permissions: (optional) Static permissions not scoped to bundles
     *   - bundle_permissions: (optional) Templates with {bundle} placeholder
     */
    public function __construct(
        private readonly array $bundles,
        private readonly array $roles,
    ) {}

    /**
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach ($this->roles as $roleId => $definition) {
            $permissions = $definition['permissions'] ?? [];
            $bundleTemplates = $definition['bundle_permissions'] ?? [];

            foreach ($this->bundles as $bundle) {
                foreach ($bundleTemplates as $template) {
                    $permissions[] = str_replace('{bundle}', $bundle, $template);
                }
            }

            $matrix[$roleId] = [
                'label' => $definition['label'],
                'permissions' => array_values(array_unique($permissions)),
            ];
        }

        return $matrix;
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function permissionsForRoles(array $roles): array
    {
        $matrix = $this->matrix();
        $permissions = [];

        foreach ($roles as $role) {
            if (!isset($matrix[$role]) || !is_array($matrix[$role]['permissions'] ?? null)) {
                continue;
            }
            array_push($permissions, ...$matrix[$role]['permissions']);
        }

        $permissions = array_values(array_unique(array_map(
            static fn(string $permission): string => trim($permission),
            array_filter($permissions, static fn(mixed $permission): bool => is_string($permission) && trim($permission) !== ''),
        )));
        sort($permissions);

        return $permissions;
    }
}
