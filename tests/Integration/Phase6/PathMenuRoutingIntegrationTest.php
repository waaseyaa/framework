<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase6;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Menu\MenuTreeBuilder;
use Waaseyaa\Path\InMemoryPathAliasManager;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathProcessor;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Integration tests for waaseyaa/path + waaseyaa/menu + waaseyaa/routing.
 *
 * Verifies that path aliases resolve correctly through the PathProcessor,
 * that menus and menu links form proper hierarchical trees, and that
 * path aliases integrate with the routing system.
 */
#[CoversNothing]
final class PathMenuRoutingIntegrationTest extends TestCase
{
    private InMemoryPathAliasManager $aliasManager;
    private PathProcessor $pathProcessor;
    private WaaseyaaRouter $router;
    private MenuTreeBuilder $treeBuilder;

    protected function setUp(): void
    {
        $this->aliasManager = new InMemoryPathAliasManager();
        $this->pathProcessor = new PathProcessor($this->aliasManager);
        $this->treeBuilder = new MenuTreeBuilder();

        // Set up router with content routes.
        $this->router = new WaaseyaaRouter();

        $this->router->addRoute(
            'node.view',
            RouteBuilder::create('/node/{node}')
                ->controller('NodeController::view')
                ->requirePermission('access content')
                ->requirement('node', '\d+')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'taxonomy_term.view',
            RouteBuilder::create('/taxonomy/term/{term}')
                ->controller('TaxonomyController::view')
                ->requirePermission('access content')
                ->requirement('term', '\d+')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'homepage',
            RouteBuilder::create('/home')
                ->controller('PageController::home')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }

    #[Test]
    public function pathAliasResolvesToSystemPath(): void
    {
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/42',
            'alias' => '/about-us',
            'langcode' => 'en',
            'status' => true,
        ]);

        $this->aliasManager->addAlias($alias);

        // Inbound: alias -> system path.
        $systemPath = $this->pathProcessor->processInbound('/about-us');
        $this->assertSame('/node/42', $systemPath);

        // Outbound: system path -> alias.
        $aliasPath = $this->pathProcessor->processOutbound('/node/42');
        $this->assertSame('/about-us', $aliasPath);
    }

    #[Test]
    public function unaliasedPathReturnsUnchanged(): void
    {
        // No alias registered for this path.
        $result = $this->pathProcessor->processInbound('/node/999');
        $this->assertSame('/node/999', $result);

        $result = $this->pathProcessor->processOutbound('/node/999');
        $this->assertSame('/node/999', $result);
    }

    #[Test]
    public function unpublishedAliasIsIgnored(): void
    {
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/10',
            'alias' => '/hidden-page',
            'langcode' => 'en',
            'status' => false,
        ]);

        $this->aliasManager->addAlias($alias);

        // Unpublished alias should not resolve.
        $result = $this->pathProcessor->processInbound('/hidden-page');
        $this->assertSame('/hidden-page', $result);

        $result = $this->pathProcessor->processOutbound('/node/10');
        $this->assertSame('/node/10', $result);
    }

    #[Test]
    public function languageSpecificAliases(): void
    {
        $enAlias = new PathAlias([
            'id' => 1,
            'path' => '/node/1',
            'alias' => '/about',
            'langcode' => 'en',
            'status' => true,
        ]);
        $frAlias = new PathAlias([
            'id' => 2,
            'path' => '/node/1',
            'alias' => '/a-propos',
            'langcode' => 'fr',
            'status' => true,
        ]);

        $this->aliasManager->addAlias($enAlias);
        $this->aliasManager->addAlias($frAlias);

        // English alias.
        $this->assertSame('/node/1', $this->pathProcessor->processInbound('/about', 'en'));
        $this->assertSame('/about', $this->pathProcessor->processOutbound('/node/1', 'en'));

        // French alias.
        $this->assertSame('/node/1', $this->pathProcessor->processInbound('/a-propos', 'fr'));
        $this->assertSame('/a-propos', $this->pathProcessor->processOutbound('/node/1', 'fr'));

        // Wrong language returns original.
        $this->assertSame('/a-propos', $this->pathProcessor->processInbound('/a-propos', 'en'));
    }

    #[Test]
    public function aliasExistsCheck(): void
    {
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/5',
            'alias' => '/products',
            'langcode' => 'en',
            'status' => true,
        ]);
        $this->aliasManager->addAlias($alias);

        $this->assertTrue($this->aliasManager->aliasExists('/products'));
        $this->assertFalse($this->aliasManager->aliasExists('/services'));
    }

    #[Test]
    public function menuAndMenuLinksCreation(): void
    {
        $menu = new Menu([
            'id' => 'main',
            'label' => 'Main Navigation',
            'description' => 'The primary site navigation.',
            'locked' => true,
        ]);

        $this->assertSame('main', $menu->id());
        $this->assertSame('Main Navigation', $menu->label());
        $this->assertSame('The primary site navigation.', $menu->getDescription());
        $this->assertTrue($menu->isLocked());
    }

    #[Test]
    public function menuTreeBuildFromFlatLinks(): void
    {
        $links = [
            new MenuLink([
                'id' => 1,
                'menu_name' => 'main',
                'title' => 'Home',
                'url' => '/home',
                'weight' => 0,
                'parent_id' => null,
            ]),
            new MenuLink([
                'id' => 2,
                'menu_name' => 'main',
                'title' => 'About',
                'url' => '/about',
                'weight' => 1,
                'parent_id' => null,
            ]),
            new MenuLink([
                'id' => 3,
                'menu_name' => 'main',
                'title' => 'Team',
                'url' => '/about/team',
                'weight' => 0,
                'parent_id' => 2,
            ]),
            new MenuLink([
                'id' => 4,
                'menu_name' => 'main',
                'title' => 'Contact',
                'url' => '/contact',
                'weight' => 2,
                'parent_id' => null,
            ]),
        ];

        $tree = $this->treeBuilder->buildTree($links);

        // 3 root-level elements.
        $this->assertCount(3, $tree);

        // Verify order by weight.
        $this->assertSame('Home', $tree[0]->link->getTitle());
        $this->assertSame('About', $tree[1]->link->getTitle());
        $this->assertSame('Contact', $tree[2]->link->getTitle());

        // About has one child: Team.
        $this->assertTrue($tree[1]->hasChildren());
        $this->assertCount(1, $tree[1]->children);
        $this->assertSame('Team', $tree[1]->children[0]->link->getTitle());

        // Home and Contact have no children.
        $this->assertFalse($tree[0]->hasChildren());
        $this->assertFalse($tree[2]->hasChildren());
    }

    #[Test]
    public function menuTreeDepthCalculation(): void
    {
        $links = [
            new MenuLink(['id' => 1, 'menu_name' => 'main', 'title' => 'Root', 'url' => '/', 'weight' => 0]),
            new MenuLink(['id' => 2, 'menu_name' => 'main', 'title' => 'Level 1', 'url' => '/l1', 'weight' => 0, 'parent_id' => 1]),
            new MenuLink(['id' => 3, 'menu_name' => 'main', 'title' => 'Level 2', 'url' => '/l2', 'weight' => 0, 'parent_id' => 2]),
        ];

        $tree = $this->treeBuilder->buildTree($links);

        $this->assertCount(1, $tree);
        $this->assertSame(2, $tree[0]->getDepth()); // Root -> L1 -> L2
        $this->assertSame(1, $tree[0]->children[0]->getDepth()); // L1 -> L2
        $this->assertSame(0, $tree[0]->children[0]->children[0]->getDepth()); // L2 is leaf
    }

    #[Test]
    public function menuLinksWeightOrdering(): void
    {
        $links = [
            new MenuLink(['id' => 1, 'menu_name' => 'main', 'title' => 'Third', 'url' => '/c', 'weight' => 10]),
            new MenuLink(['id' => 2, 'menu_name' => 'main', 'title' => 'First', 'url' => '/a', 'weight' => -5]),
            new MenuLink(['id' => 3, 'menu_name' => 'main', 'title' => 'Second', 'url' => '/b', 'weight' => 0]),
        ];

        $tree = $this->treeBuilder->buildTree($links);

        $this->assertSame('First', $tree[0]->link->getTitle());
        $this->assertSame('Second', $tree[1]->link->getTitle());
        $this->assertSame('Third', $tree[2]->link->getTitle());
    }

    #[Test]
    public function pathAliasCanResolveToRoutePath(): void
    {
        // Create a path alias.
        $alias = new PathAlias([
            'id' => 1,
            'path' => '/node/42',
            'alias' => '/my-article',
            'status' => true,
        ]);
        $this->aliasManager->addAlias($alias);

        // Resolve alias to system path.
        $systemPath = $this->pathProcessor->processInbound('/my-article');
        $this->assertSame('/node/42', $systemPath);

        // Now match the system path against the router.
        $params = $this->router->match($systemPath);
        $this->assertSame('node.view', $params['_route']);
        $this->assertSame('42', $params['node']);
    }

    #[Test]
    public function menuLinksCanUseAliasedPaths(): void
    {
        // Set up aliases.
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 1,
            'path' => '/node/1',
            'alias' => '/about',
            'status' => true,
        ]));
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 2,
            'path' => '/node/2',
            'alias' => '/services',
            'status' => true,
        ]));

        // Create menu links using alias paths.
        $links = [
            new MenuLink([
                'id' => 1,
                'menu_name' => 'main',
                'title' => 'About Us',
                'url' => '/about',
                'weight' => 0,
            ]),
            new MenuLink([
                'id' => 2,
                'menu_name' => 'main',
                'title' => 'Services',
                'url' => '/services',
                'weight' => 1,
            ]),
        ];

        $tree = $this->treeBuilder->buildTree($links);
        $this->assertCount(2, $tree);

        // Resolve each link URL through the path processor.
        $aboutSystemPath = $this->pathProcessor->processInbound($tree[0]->link->getUrl());
        $this->assertSame('/node/1', $aboutSystemPath);

        $servicesSystemPath = $this->pathProcessor->processInbound($tree[1]->link->getUrl());
        $this->assertSame('/node/2', $servicesSystemPath);

        // Match against router.
        $aboutParams = $this->router->match($aboutSystemPath);
        $this->assertSame('node.view', $aboutParams['_route']);
        $this->assertSame('1', $aboutParams['node']);
    }

    #[Test]
    public function menuLinkProperties(): void
    {
        $link = new MenuLink([
            'id' => 1,
            'menu_name' => 'main',
            'title' => 'External Link',
            'url' => 'https://example.com',
            'weight' => 5,
            'enabled' => true,
            'expanded' => true,
        ]);

        $this->assertSame('main', $link->getMenuName());
        $this->assertSame('External Link', $link->getTitle());
        $this->assertSame('https://example.com', $link->getUrl());
        $this->assertTrue($link->isExternal());
        $this->assertTrue($link->isEnabled());
        $this->assertTrue($link->isExpanded());
        $this->assertTrue($link->isRoot());

        $internalLink = new MenuLink([
            'id' => 2,
            'menu_name' => 'main',
            'title' => 'Internal Link',
            'url' => '/about',
            'parent_id' => 1,
        ]);

        $this->assertFalse($internalLink->isExternal());
        $this->assertFalse($internalLink->isRoot());
        $this->assertSame(1, $internalLink->getParentId());
    }

    #[Test]
    public function disabledMenuLinksAreStillInTree(): void
    {
        $links = [
            new MenuLink([
                'id' => 1,
                'menu_name' => 'main',
                'title' => 'Active Link',
                'url' => '/active',
                'weight' => 0,
                'enabled' => true,
            ]),
            new MenuLink([
                'id' => 2,
                'menu_name' => 'main',
                'title' => 'Disabled Link',
                'url' => '/disabled',
                'weight' => 1,
                'enabled' => false,
            ]),
        ];

        $tree = $this->treeBuilder->buildTree($links);

        // Both links are in the tree (builder doesn't filter by enabled).
        $this->assertCount(2, $tree);
        $this->assertTrue($tree[0]->link->isEnabled());
        $this->assertFalse($tree[1]->link->isEnabled());
    }

    #[Test]
    public function menuConfigExport(): void
    {
        $menu = new Menu([
            'id' => 'footer',
            'label' => 'Footer Menu',
            'description' => 'Links in the site footer.',
            'locked' => false,
        ]);

        $config = $menu->toConfig();

        $this->assertSame('footer', $config['id']);
        $this->assertSame('Footer Menu', $config['label']);
        $this->assertSame('Links in the site footer.', $config['description']);
        $this->assertFalse($config['locked']);
    }

    #[Test]
    public function routerUrlGenerationWithPathAlias(): void
    {
        // Generate a route URL.
        $url = $this->router->generate('node.view', ['node' => 42]);
        $this->assertSame('/node/42', $url);

        // Set up a path alias for this URL.
        $this->aliasManager->addAlias(new PathAlias([
            'id' => 1,
            'path' => '/node/42',
            'alias' => '/great-article',
            'status' => true,
        ]));

        // Process the generated URL through the outbound path processor.
        $aliased = $this->pathProcessor->processOutbound($url);
        $this->assertSame('/great-article', $aliased);
    }

    #[Test]
    public function emptyMenuTreeBuildsEmpty(): void
    {
        $tree = $this->treeBuilder->buildTree([]);
        $this->assertSame([], $tree);
    }
}
