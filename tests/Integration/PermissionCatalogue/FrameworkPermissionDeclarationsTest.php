<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PermissionCatalogue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Media\MediaPermissions;
use Waaseyaa\Node\NodePermissions;
use Waaseyaa\Taxonomy\TaxonomyPermissions;
use Waaseyaa\Workflows\WorkflowPermissions;

final class FrameworkPermissionDeclarationsTest extends TestCase
{
    #[Test]
    public function installed_package_manifests_own_the_fixed_policy_permissions_once(): void
    {
        $owners = [];
        foreach (['node', 'media', 'menu', 'taxonomy', 'workflows'] as $package) {
            $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . "/packages/$package/composer.json"), true, 512, JSON_THROW_ON_ERROR);
            foreach ($manifest['extra']['waaseyaa']['permissions'] ?? [] as $id => $definition) {
                self::assertArrayNotHasKey($id, $owners, sprintf('Permission "%s" has two package owners.', $id));
                $owners[$id] = [$package, $definition];
            }
        }

        foreach ([
            NodePermissions::ADMINISTER,
            NodePermissions::ACCESS_CONTENT,
            NodePermissions::VIEW_OWN_UNPUBLISHED,
            MediaPermissions::ADMINISTER,
            MediaPermissions::ACCESS,
            MediaPermissions::VIEW_OWN_UNPUBLISHED,
            'administer menu',
            TaxonomyPermissions::ADMINISTER,
        ] as $id) {
            self::assertArrayHasKey($id, $owners);
        }

        $workflowManifest = array_filter($owners, static fn(array $owned): bool => $owned[0] === 'workflows');
        self::assertSame(array_keys(WorkflowPermissions::defaultEditorial()), array_keys($workflowManifest));
        foreach (WorkflowPermissions::defaultEditorial() as $id => $definition) {
            self::assertSame($definition, $owners[$id][1], $id);
        }
    }
}
