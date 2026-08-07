<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\Tests\Fixtures\SymfonyFreeRedirectController;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversNothing]
final class ControllerSymfonyImportBoundaryTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/SymfonyFreeRedirectController.php';

    #[Test]
    public function application_controller_uses_only_waaseyaa_http_types(): void
    {
        $source = file_get_contents(self::FIXTURE);

        self::assertNotFalse($source);
        self::assertDoesNotMatchRegularExpression('/^\s*use\s+Symfony\\\\/m', $source);
    }

    #[Test]
    public function symfony_free_application_controller_returns_a_working_redirect(): void
    {
        $router = new WaaseyaaRouter();
        $router->addRoute('todo.show', RouteBuilder::create('/todos/{todo}')->methods('GET')->build());
        $controller = new SymfonyFreeRedirectController(new Redirector($router));

        $response = $controller->store();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/todos/42', $response->getTargetUrl());
    }
}
