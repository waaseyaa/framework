<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\SSR\ArrayViewModeConfig;
use Waaseyaa\SSR\EntityRenderer;
use Waaseyaa\SSR\FieldFormatterRegistry;
use Waaseyaa\SSR\ViewMode;

#[CoversClass(EntityRenderer::class)]
final class EntityRendererTest extends TestCase
{
    #[Test]
    public function renders_fields_by_view_mode_config_and_weights(): void
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: RendererTestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'text_long'],
                'created' => ['type' => 'datetime'],
            ],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->with('node')->willReturn($definition);

        $config = new ArrayViewModeConfig([
            'node' => [
                'teaser' => [
                    'body' => ['formatter' => 'string', 'weight' => 10],
                    'created' => ['formatter' => 'datetime', 'settings' => ['format' => 'Y-m-d'], 'weight' => 20],
                    'title' => ['formatter' => 'string', 'weight' => 0],
                ],
            ],
        ]);

        $renderer = new EntityRenderer($manager, new FieldFormatterRegistry(), $config);
        $entity = new RendererTestEntity('node', [
            'id' => 1,
            'bundle' => 'article',
            'title' => 'Hello',
            'body' => '<p>Body</p>',
            'created' => 1767571200,
        ]);

        $bag = $renderer->render($entity, ViewMode::teaser());

        $this->assertSame('node', $bag['entity_type']);
        $this->assertSame('article', $bag['bundle']);
        $this->assertSame('teaser', $bag['view_mode']);
        $this->assertSame(['title', 'body', 'created'], array_keys($bag['fields']));
        $this->assertSame('Hello', $bag['fields']['title']['formatted']);
        $this->assertSame('&lt;p&gt;Body&lt;/p&gt;', $bag['fields']['body']['formatted']);
        $this->assertSame('2026-01-05', $bag['fields']['created']['formatted']);
        $this->assertSame('node.article.teaser.html.twig', $bag['template_suggestions'][0]);
        $this->assertSame('node.article.full.html.twig', $bag['template_suggestions'][1]);
        $this->assertSame('node.teaser.html.twig', $bag['template_suggestions'][2]);
        $this->assertSame('node.full.html.twig', $bag['template_suggestions'][3]);
    }

    #[Test]
    public function falls_back_to_default_display_when_no_view_mode_config_exists(): void
    {
        $definition = new EntityType(
            id: 'node',
            label: 'Node',
            class: RendererTestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            fieldDefinitions: [
                'body' => ['type' => 'text_long'],
                'status' => ['type' => 'boolean'],
            ],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->with('node')->willReturn($definition);

        $renderer = new EntityRenderer($manager, new FieldFormatterRegistry(), new ArrayViewModeConfig());
        $entity = new RendererTestEntity('node', [
            'id' => 1,
            'title' => 'Skipped key',
            'body' => '<p>Body</p>',
            'status' => true,
        ]);

        $bag = $renderer->render($entity, 'full');
        $this->assertSame(['body', 'status'], array_keys($bag['fields']));
        $this->assertSame('<p>Body</p>', $bag['fields']['body']['formatted']);
        $this->assertSame('Yes', $bag['fields']['status']['formatted']);
    }
}

final readonly class RendererTestEntity implements EntityInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private string $entityType,
        private array $values,
    ) {}

    public function id(): int|string|null
    {
        return $this->values['id'] ?? null;
    }

    public function uuid(): string
    {
        return (string) ($this->values['uuid'] ?? '');
    }

    public function label(): string
    {
        return (string) ($this->values['title'] ?? '');
    }

    public function getEntityTypeId(): string
    {
        return $this->entityType;
    }

    public function bundle(): string
    {
        return (string) ($this->values['bundle'] ?? $this->entityType);
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }

    public function toArray(): array
    {
        return $this->values;
    }

    public function language(): string
    {
        return 'en';
    }
}
