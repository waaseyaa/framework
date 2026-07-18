<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\FieldReadPagePerformance\Fixtures;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class FieldReadPagePerformanceProvider extends ServiceProvider
{
    public function register(): void
    {
        $registry = $this->resolve(FieldDefinitionRegistryInterface::class);
        $fields = [];
        foreach (FieldReadPageCorpus::contentFieldNames() as $field) {
            $fields[] = new FieldDefinition(
                name: $field,
                type: 'text',
                targetEntityTypeId: 'node',
                targetBundle: 'article',
                stored: FieldStorage::Data,
                read: FieldReadLevel::Public,
            );
        }
        $registry->registerBundleFields('node', 'article', $fields);
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $router->addRoute(
            'performance.members',
            RouteBuilder::create('/members')
                ->controller(MembersDirectoryController::class . '::index')
                ->requireAuthentication()
                ->render()
                ->methods('GET')
                ->build(),
        );
    }
}
