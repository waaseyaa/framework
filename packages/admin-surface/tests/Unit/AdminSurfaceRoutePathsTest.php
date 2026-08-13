<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\AdminSurfaceRoutePaths;

#[CoversClass(AdminSurfaceRoutePaths::class)]
final class AdminSurfaceRoutePathsTest extends TestCase
{
    #[Test]
    public function generateSessionAndCatalogMatchPathConstants(): void
    {
        static::assertSame(AdminSurfaceRoutePaths::PATH_SESSION, AdminSurfaceRoutePaths::generate('admin_surface.session'));
        static::assertSame(AdminSurfaceRoutePaths::PATH_CATALOG, AdminSurfaceRoutePaths::generate('admin_surface.catalog'));
    }

    #[Test]
    public function generateBuildsParameterizedPaths(): void
    {
        static::assertSame('/admin/_surface/article', AdminSurfaceRoutePaths::generate('admin_surface.list', ['type' => 'article']));
        static::assertSame('/admin/_surface/article/42', AdminSurfaceRoutePaths::generate('admin_surface.get', [
            'type' => 'article',
            'id' => '42',
        ]));
        static::assertSame('/admin/_surface/article/action/create', AdminSurfaceRoutePaths::generate('admin_surface.action', [
            'type' => 'article',
            'action' => 'create',
        ]));
        static::assertSame('/admin/_surface/page-builder/pages/definitions', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.definitions',
            ['surface' => 'pages'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.draft',
            ['surface' => 'pages', 'id' => '42'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42/commands', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.command',
            ['surface' => 'pages', 'id' => '42'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42/preview', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.preview',
            ['surface' => 'pages', 'id' => '42'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42/revisions', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.history',
            ['surface' => 'pages', 'id' => '42'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42/revisions/7', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.revision',
            ['surface' => 'pages', 'id' => '42', 'revision' => '7'],
        ));
        static::assertSame('/admin/_surface/page-builder/pages/42/restore', AdminSurfaceRoutePaths::generate(
            'admin_surface.page_builder.restore',
            ['surface' => 'pages', 'id' => '42'],
        ));
    }

    #[Test]
    public function generateEncodesSegments(): void
    {
        static::assertSame('/admin/_surface/a%20b', AdminSurfaceRoutePaths::generate('admin_surface.list', ['type' => 'a b']));
    }

    #[Test]
    public function generateThrowsOnUnknownRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown admin surface route name');
        AdminSurfaceRoutePaths::generate('admin_surface.nope');
    }

    #[Test]
    public function generateThrowsOnMissingListType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type');
        AdminSurfaceRoutePaths::generate('admin_surface.list', []);
    }
}
