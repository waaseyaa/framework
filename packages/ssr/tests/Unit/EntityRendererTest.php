<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
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
        $definition = TestEntityType::stub(
            id: 'node',
            class: RendererTestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            label: 'Node',
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
        $definition = TestEntityType::stub(
            id: 'node',
            class: RendererTestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            label: 'Node',
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

    #[Test]
    public function drops_internal_and_credential_fields_from_default_display(): void
    {
        // Entity type with a normal field, an internal:true field, and a bare 'password' key.
        // The 'internal' key in the array definition is not a standard FieldDefinition argument,
        // so TestEntityType::stub / EntityType::getFieldDefinitions() folds it into settings[],
        // making getSetting('internal') === true.
        $definition = TestEntityType::stub(
            id: 'node',
            class: RendererTestEntity::class,
            keys: ['id' => 'id', 'label' => 'title'],
            label: 'Node',
            fieldDefinitions: [
                'body'          => ['type' => 'text_long'],
                'secret_token'  => ['type' => 'string', 'internal' => true],
            ],
        );

        $manager = $this->createMock(EntityTypeManagerInterface::class);
        $manager->method('getDefinition')->with('node')->willReturn($definition);

        // Use empty view-mode config so the renderer falls through to buildDefaultDisplay(),
        // which includes every scalar value not in entityKeys. All three candidate fields
        // (body, secret_token, password) are scalars, so without the internal-field guard
        // all three would appear in the output.
        $renderer = new EntityRenderer($manager, new FieldFormatterRegistry(), new ArrayViewModeConfig());
        $entity = new RendererTestEntity('node', [
            'id'           => 1,
            'title'        => 'Article title',
            'body'         => 'Some content',
            'secret_token' => 'tok_supersecret',
            'password'     => 'hashed$2y$10$...',
        ]);

        $bag = $renderer->render($entity, 'full');

        // The normal field must be present.
        $this->assertArrayHasKey('body', $bag['fields'], 'Normal field "body" must appear in rendered output.');

        // The internal:true field must be dropped.
        $this->assertArrayNotHasKey('secret_token', $bag['fields'], 'Field with settings[internal=>true] must be dropped from rendered output.');

        // The always-internal credential field must be dropped even without a FieldDefinition.
        $this->assertArrayNotHasKey('password', $bag['fields'], 'Field named "password" must always be dropped from rendered output.');
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
