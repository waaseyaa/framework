<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\SSR\RenderController;

#[CoversClass(RenderController::class)]
final class RenderControllerTest extends TestCase
{
    #[Test]
    public function renderPathUsesTwigTemplateWhenAvailable(): void
    {
        $twig = new Environment(new ArrayLoader([
            'page.html.twig' => '<main>{{ path }}</main>',
        ]));
        $controller = new RenderController($twig);

        $response = $controller->renderPath('about');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('<main>/about</main>', $response->content);
        $this->assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    #[Test]
    public function renderPathFallsBackWhenNoTemplateIsFound(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $controller = new RenderController($twig);

        $response = $controller->renderPath('/missing');

        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('Path: /missing', $response->content);
    }
}
