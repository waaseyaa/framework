<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Http\AdminSpaHttpController;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(AdminSurfaceServiceProvider::class)]
#[CoversClass(AdminSpaHttpController::class)]
final class AdminSpaTerminalBindingTest extends TestCase
{
    public function testSelectedSpaReadsCurrentAssetsWithoutResolvingHosts(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_spa_terminal_' . bin2hex(random_bytes(8));
        mkdir($root . '/public/admin', 0o755, true);
        $bus = new class implements KernelServicesInterface {
            public int $reads = 0;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                throw new \LogicException('SPA selection resolved an unrelated host.');
            }
        };
        try {
            $provider = new AdminSurfaceServiceProvider();
            $provider->setKernelContext($root, ['session' => ['cookie' => ['csrf_name' => 'APP$1-XSRF']]], []);
            $provider->setKernelServices($bus);
            $provider->register();
            $routes = array_column(iterator_to_array($provider->routeDefinitions(new RouteContributionContext(AdminSurfaceServiceProvider::class, 0))), null, 'name');
            $definition = $routes['admin_spa'];
            self::assertFileDoesNotExist($root . '/public/admin/app.js');
            file_put_contents($root . '/public/admin/app.js', 'late application override');
            $request = Request::create('/admin/app.js');
            $request->attributes->add(['_route' => 'admin_spa', '_controller' => $definition->handler->id, 'path' => 'app.js']);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $callable);
            $response = new ControllerDispatcher([])->dispatch($request);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/javascript', $response->headers->get('Content-Type'));
            self::assertSame('late application override', $response->getContent());
            self::assertSame($response->getContent(), $callable($request, path: 'conflicting')->getContent());
            file_put_contents($root . '/public/admin/app.js', 'current application override');
            self::assertSame('current application override', $callable($request)->getContent());
            file_put_contents($root . '/public/admin/index.html', '<html>csrfCookieName:"XSRF-TOKEN"</html>');
            $request->attributes->set('path', 'index.html');
            self::assertSame('<html>csrfCookieName:"APP$1-XSRF"</html>', $callable($request)->getContent());
            $request->attributes->set('path', 'missing-spa-route');
            self::assertSame('<html>csrfCookieName:"APP$1-XSRF"</html>', $callable($request)->getContent());
            self::assertSame(0, $bus->reads);
            self::assertNotSame($provider->resolve(AdminSpaHttpController::class), $provider->resolve(AdminSpaHttpController::class));
        } finally {
            new Filesystem()->remove($root);
        }
    }

    public function testSpaDeliveryKeepsVendorFallbackAndLegacyDirectCall(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_spa_fallback_' . bin2hex(random_bytes(8));
        mkdir($root . '/vendor-dist', 0o755, true);
        try {
            $controller = new AdminSpaHttpController($root, $root . '/vendor-dist', '__Host-XSRF-TOKEN');
            self::assertSame(200, $controller->serve()->getStatusCode());
            file_put_contents($root . '/vendor-dist/index.html', '<html>csrfCookieName:"XSRF-TOKEN"</html>');
            self::assertSame('<html>csrfCookieName:"__Host-XSRF-TOKEN"</html>', $controller->serve(null, 'missing')->getContent());
            file_put_contents($root . '/vendor-dist/app.js', 'vendor asset');
            self::assertSame('vendor asset', $controller->serve(null, 'app.js')->getContent());
            $request = Request::create('/admin/../vendor-dist/app.js');
            $request->attributes->set('path', '../vendor-dist/app.js');
            self::assertSame('<html>csrfCookieName:"__Host-XSRF-TOKEN"</html>', $controller->serve($request)->getContent());
            $request->attributes->set('path', ['invalid']);
            $this->expectException(\InvalidArgumentException::class);
            $controller->serve($request);
        } finally {
            new Filesystem()->remove($root);
        }
    }
}
