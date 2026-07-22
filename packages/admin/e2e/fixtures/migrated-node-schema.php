<?php

declare(strict_types=1);

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$registry = new FieldDefinitionRegistry();
$manager = new EntityTypeManager(new EventDispatcher(), fieldRegistry: $registry);
$manager->registerEntityType(EntityType::fromClass(Node::class, group: 'content'));
$registry->registerBundleFields('node', 'post', [
    new FieldDefinition(
        'source_status',
        'string',
        settings: ['weight' => 7],
        targetEntityTypeId: 'node',
        targetBundle: 'post',
        label: 'WordPress status',
        read: FieldReadLevel::Public,
    ),
]);

// Match the SFN application's direct/custom host construction. In particular,
// do not route through AdminSurfaceServiceProvider or pass an exclusion list.
$host = new GenericAdminSurfaceHost(
    entityTypeManager: $manager,
    accessHandler: new EntityAccessHandler([new NodeAccessPolicy()]),
    schemaPresenter: new SchemaPresenter($registry),
);
$principal = new AuthorizationPrincipal(
    42,
    true,
    ['administrator'],
    ['administer content', 'administer nodes'],
    'browser-regression',
);
$request = Request::create('/admin/_surface/node/action/schema', 'POST');
$request->attributes->set('_account', $principal);
$request->attributes->set('_authorization_principal', $principal);
$host->resolveSession($request);

echo json_encode($host->action('node', 'schema', ['bundle' => 'post']), JSON_THROW_ON_ERROR);
