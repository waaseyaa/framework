<?php

declare(strict_types=1);

namespace Waaseyaa\Menu\Tests\Unit;

use Waaseyaa\Menu\MenuLink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Waaseyaa\Menu\MenuLink
 */
final class MenuLinkTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'url' => '/', 'menu_name' => 'main']);

        $this->assertSame('menu_link', $link->getEntityTypeId());
    }

    public function testIdAndUuid(): void
    {
        $link = new MenuLink(['id' => 42, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertSame(42, $link->id());
        $this->assertNotEmpty($link->uuid());
    }

    public function testGetSetTitle(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertSame('Home', $link->getTitle());

        $link->setTitle('Dashboard');
        $this->assertSame('Dashboard', $link->getTitle());
    }

    public function testLabelReturnsTitle(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'About Us', 'menu_name' => 'main']);

        $this->assertSame('About Us', $link->label());
    }

    public function testGetSetUrl(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'url' => '/home', 'menu_name' => 'main']);

        $this->assertSame('/home', $link->getUrl());

        $link->setUrl('/dashboard');
        $this->assertSame('/dashboard', $link->getUrl());
    }

    public function testGetMenuName(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'footer']);

        $this->assertSame('footer', $link->getMenuName());
    }

    public function testGetSetObjectTarget(): void
    {
        $link = new MenuLink([
            'id' => 1,
            'title' => 'About',
            'menu_name' => 'main',
            'target_entity_type' => 'node',
            'target_entity_id' => 42,
        ]);

        $this->assertSame('node', $link->getTargetEntityType());
        $this->assertSame(42, $link->getTargetEntityId());

        $link->setTargetEntityType('taxonomy_term')->setTargetEntityId('community');
        $this->assertSame('taxonomy_term', $link->getTargetEntityType());
        $this->assertSame('community', $link->getTargetEntityId());
    }

    public function testObjectTargetDefaultsToEmpty(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'External', 'url' => 'https://example.com', 'menu_name' => 'main']);

        $this->assertSame('', $link->getTargetEntityType());
        $this->assertNull($link->getTargetEntityId());
    }

    public function testGetSetTarget(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'External', 'menu_name' => 'main', 'target' => '_blank']);

        $this->assertSame('_blank', $link->getTarget());
        $link->setTarget('_self');
        $this->assertSame('_self', $link->getTarget());
    }

    public function testBundleReturnsMenuName(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertSame('main', $link->bundle());
    }

    public function testGetSetParentId(): void
    {
        $link = new MenuLink(['id' => 2, 'title' => 'Sub page', 'menu_name' => 'main', 'parent_id' => 1]);

        $this->assertSame(1, $link->getParentId());

        $link->setParentId(5);
        $this->assertSame(5, $link->getParentId());

        $link->setParentId(null);
        $this->assertNull($link->getParentId());
    }

    public function testParentIdDefaultsToNull(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Root', 'menu_name' => 'main']);

        $this->assertNull($link->getParentId());
    }

    public function testGetSetWeight(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main', 'weight' => 5]);

        $this->assertSame(5, $link->getWeight());

        $link->setWeight(-3);
        $this->assertSame(-3, $link->getWeight());
    }

    public function testWeightDefaultsToZero(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertSame(0, $link->getWeight());
    }

    public function testIsEnabledDefaultsToTrue(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertTrue($link->isEnabled());
    }

    public function testGetSetEnabled(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main', 'enabled' => false]);

        $this->assertFalse($link->isEnabled());

        $link->setEnabled(true);
        $this->assertTrue($link->isEnabled());
    }

    public function testIsExpandedDefaultsToFalse(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Home', 'menu_name' => 'main']);

        $this->assertFalse($link->isExpanded());
    }

    public function testGetSetExpanded(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Products', 'menu_name' => 'main', 'expanded' => true]);

        $this->assertTrue($link->isExpanded());

        $link->setExpanded(false);
        $this->assertFalse($link->isExpanded());
    }

    public function testIsExternalWithHttp(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Google', 'url' => 'http://google.com', 'menu_name' => 'main']);

        $this->assertTrue($link->isExternal());
    }

    public function testIsExternalWithHttps(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Google', 'url' => 'https://google.com', 'menu_name' => 'main']);

        $this->assertTrue($link->isExternal());
    }

    public function testIsNotExternalWithInternalPath(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'About', 'url' => '/about', 'menu_name' => 'main']);

        $this->assertFalse($link->isExternal());
    }

    public function testIsNotExternalWithEmptyUrl(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Placeholder', 'menu_name' => 'main']);

        $this->assertFalse($link->isExternal());
    }

    public function testIsRootWhenParentIdNull(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Root', 'menu_name' => 'main']);

        $this->assertTrue($link->isRoot());
    }

    public function testIsRootWhenParentIdZero(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Root', 'menu_name' => 'main', 'parent_id' => 0]);

        $this->assertTrue($link->isRoot());
    }

    public function testIsNotRootWhenHasParent(): void
    {
        $link = new MenuLink(['id' => 2, 'title' => 'Child', 'menu_name' => 'main', 'parent_id' => 1]);

        $this->assertFalse($link->isRoot());
    }

    public function testToArray(): void
    {
        $link = new MenuLink([
            'id' => 1,
            'title' => 'Home',
            'url' => '/',
            'menu_name' => 'main',
            'weight' => 0,
            'parent_id' => null,
            'enabled' => true,
            'expanded' => false,
            'target' => '_blank',
            'target_entity_type' => 'node',
            'target_entity_id' => '42',
        ]);

        $array = $link->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame('Home', $array['title']);
        $this->assertSame('/', $array['url']);
        $this->assertSame('main', $array['menu_name']);
        $this->assertSame(0, $array['weight']);
        $this->assertNull($array['parent_id']);
        $this->assertTrue($array['enabled']);
        $this->assertFalse($array['expanded']);
        $this->assertSame('_blank', $array['target']);
        $this->assertSame('node', $array['target_entity_type']);
        $this->assertSame('42', $array['target_entity_id']);
        $this->assertArrayHasKey('uuid', $array);
    }

    public function testIsNewWhenNoId(): void
    {
        $link = new MenuLink(['title' => 'New link', 'menu_name' => 'main']);

        $this->assertTrue($link->isNew());
    }

    public function testIsNotNewWhenHasId(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Existing', 'menu_name' => 'main']);

        $this->assertFalse($link->isNew());
    }

    public function testFluentInterface(): void
    {
        $link = new MenuLink(['id' => 1, 'title' => 'Test', 'menu_name' => 'main']);

        $result = $link
            ->setTitle('Updated')
            ->setUrl('/new')
            ->setTargetEntityType('node')
            ->setTargetEntityId(42)
            ->setParentId(5)
            ->setWeight(10)
            ->setEnabled(false)
            ->setExpanded(true);

        $this->assertSame($link, $result);
        $this->assertSame('Updated', $link->getTitle());
        $this->assertSame('/new', $link->getUrl());
        $this->assertSame('node', $link->getTargetEntityType());
        $this->assertSame(42, $link->getTargetEntityId());
        $this->assertSame(5, $link->getParentId());
        $this->assertSame(10, $link->getWeight());
        $this->assertFalse($link->isEnabled());
        $this->assertTrue($link->isExpanded());
    }
}
