<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PermissionCatalogue\Fixtures;

use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesPermissionsInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesRolesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\MediaPermissions;
use Waaseyaa\Node\NodePermissions;
use Waaseyaa\Taxonomy\TaxonomyPermissions;
use Waaseyaa\User\Role;
use Waaseyaa\Workflows\WorkflowPermissions;

/** @internal Test application using Framework-owned permission families. */
final class FrameworkPermissionRolesProvider extends ServiceProvider implements ProvidesRolesInterface, ProvidesPermissionsInterface
{
    /** @var array<string, array{title: string, description: string}>|null */
    private ?array $dynamicDefinitions = null;

    public function register(): void {}

    public function permissions(): array
    {
        return $this->dynamicDefinitions ??= array_replace(
            NodePermissions::forBundles(['article']),
            MediaPermissions::forTypes(['image']),
            TaxonomyPermissions::forVocabularies(['category']),
            WorkflowPermissions::forWorkflow('community', ['approve' => ['label' => 'Approve']]),
        );
    }

    public function roles(): iterable
    {
        yield new Role('editor', 'Editor', [
            NodePermissions::ACCESS_CONTENT,
            NodePermissions::VIEW_OWN_UNPUBLISHED,
            NodePermissions::create('article'),
            NodePermissions::editAny('article'),
            MediaPermissions::ACCESS,
            MediaPermissions::VIEW_OWN_UNPUBLISHED,
            MediaPermissions::create('image'),
            TaxonomyPermissions::create('category'),
            WorkflowPermissions::transition('editorial', 'publish'),
            WorkflowPermissions::transition('community', 'approve'),
            'administer menu',
            'tool.content.search',
        ]);
    }
}
