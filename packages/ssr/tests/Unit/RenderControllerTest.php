<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\SSR\ArrayViewModeConfig;
use Waaseyaa\SSR\EntityRenderer;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\RenderController;
use Waaseyaa\SSR\ViewMode;

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

    #[Test]
    public function renderEntityUsesTemplateSuggestions(): void
    {
        $twig = new Environment(new ArrayLoader([
            'node.full.html.twig' => '<article>{{ fields.title.formatted|raw }}</article>',
        ]));

        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: RenderControllerEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: ['title' => ['type' => 'string']],
        );
        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->willReturn($definition);

        $renderer = new EntityRenderer($manager, new FieldFormatterRegistry(), new ArrayViewModeConfig([
            'node' => [
                'full' => [
                    'title' => ['formatter' => 'string', 'weight' => 0],
                ],
            ],
        ]));
        $controller = new RenderController($twig, $renderer);

        $response = $controller->renderEntity(new RenderControllerEntity('node', [
            'id' => 1,
            'title' => 'Rendered',
        ]), ViewMode::full());

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('<article>Rendered</article>', $response->content);
    }

    #[Test]
    public function renderNotFoundReturns404Response(): void
    {
        $twig = new Environment(new ArrayLoader([
            '404.html.twig' => '<h1>Not Found {{ path }}</h1>',
        ]));
        $controller = new RenderController($twig);

        $response = $controller->renderNotFound('/missing');

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('<h1>Not Found /missing</h1>', $response->content);
    }
}

final readonly class RenderControllerEntity implements \Waaseyaa\Entity\EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private string $entityTypeId,
        private array $values,
    ) {}

    public function id(): int|string|null { return $this->values['id'] ?? null; }
    public function uuid(): string { return (string) ($this->values['uuid'] ?? ''); }
    public function label(): string { return (string) ($this->values['title'] ?? ''); }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return (string) ($this->values['bundle'] ?? $this->entityTypeId); }
    public function isNew(): bool { return false; }
    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}
