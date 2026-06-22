<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Markdown;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Api\Markdown\EntityMarkdownPresenter;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\ViewModeConfigInterface;

#[CoversClass(EntityMarkdownPresenter::class)]
final class EntityMarkdownPresenterTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->entityTypeManager->registerEntityType(TestEntityType::stub(
            'article',
            [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
                'body' => new FieldDefinition(name: 'body', type: 'text_long'),
                'hero' => new FieldDefinition(name: 'hero', type: 'image'),
                'related' => new FieldDefinition(name: 'related', type: 'entity_reference'),
                'flagged' => new FieldDefinition(name: 'flagged', type: 'boolean'),
                'two_factor_secret' => new FieldDefinition(name: 'two_factor_secret', type: 'string', settings: ['internal' => true]),
                'secret_note' => new FieldDefinition(name: 'secret_note', type: 'string'),
            ],
            keys: TestEntity::definitionKeys(),
            class: TestEntity::class,
            label: 'Article',
        ));
    }

    private function presenter(ViewModeConfigInterface $viewModeConfig): EntityMarkdownPresenter
    {
        return new EntityMarkdownPresenter(
            new ResourceSerializer($this->entityTypeManager),
            $this->entityTypeManager,
            $viewModeConfig,
        );
    }

    private function emptyDisplay(): ViewModeConfigInterface
    {
        return new class implements ViewModeConfigInterface {
            public function getDisplay(string $entityTypeId, string $viewMode): array
            {
                return [];
            }
        };
    }

    private function sampleEntity(array $overrides = []): TestEntity
    {
        return new TestEntity(array_merge([
            'id' => 7,
            'uuid' => 'u-7',
            'type' => 'article',
            'title' => 'My Title',
            'body' => 'Hello world.',
            'related' => '42',
            'hero' => 'https://cdn.example/img.png',
            'flagged' => true,
        ], $overrides));
    }

    #[Test]
    public function renders_front_matter_and_title_heading(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity(), 'full', null, null, '/articles/my-title');

        self::assertStringStartsWith("---\n", $md);
        self::assertStringContainsString("type: article\n", $md);
        self::assertStringContainsString("id: 7\n", $md);
        self::assertStringContainsString("uuid: u-7\n", $md);
        self::assertStringContainsString("view_mode: full\n", $md);
        self::assertStringContainsString('url: /articles/my-title', $md);
        self::assertStringContainsString("# My Title\n", $md);
    }

    #[Test]
    public function label_field_is_not_repeated_as_a_body_section(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity());

        self::assertStringContainsString('# My Title', $md);
        self::assertStringNotContainsString('## Title', $md);
        // bundle/langcode key fields are metadata, not body sections.
        self::assertStringNotContainsString('## Type', $md);
    }

    #[Test]
    public function entity_reference_renders_as_markdown_link_with_default_pattern(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity());

        self::assertStringContainsString('[42](/entity/42)', $md);
    }

    #[Test]
    public function entity_reference_uses_view_mode_settings(): void
    {
        $display = new class implements ViewModeConfigInterface {
            public function getDisplay(string $entityTypeId, string $viewMode): array
            {
                return [
                    'related' => [
                        'settings' => ['label' => 'Related thing', 'url_pattern' => '/node/{id}'],
                        'weight' => 0,
                    ],
                ];
            }
        };

        $md = $this->presenter($display)->present($this->sampleEntity());

        self::assertStringContainsString('[Related thing](/node/42)', $md);
    }

    #[Test]
    public function view_mode_display_is_unioned_with_all_stored_fields_so_body_is_not_dropped(): void
    {
        // A display config that lists ONLY `related` must NOT drop the stored
        // `body` field — every access-safe stored field is unioned in (FR-005).
        $display = new class implements ViewModeConfigInterface {
            public function getDisplay(string $entityTypeId, string $viewMode): array
            {
                return ['related' => ['settings' => ['label' => 'Rel', 'url_pattern' => '/n/{id}'], 'weight' => 0]];
            }
        };

        $md = $this->presenter($display)->present($this->sampleEntity());

        // Configured field keeps its settings...
        self::assertStringContainsString('[Rel](/n/42)', $md);
        // ...and the stored body still renders (was dropped before the union fix).
        self::assertStringContainsString('## Body', $md);
        self::assertStringContainsString('Hello world.', $md);
    }

    #[Test]
    public function image_renders_as_alt_texted_markdown_image(): void
    {
        $display = new class implements ViewModeConfigInterface {
            public function getDisplay(string $entityTypeId, string $viewMode): array
            {
                return ['hero' => ['settings' => ['alt' => 'Hero alt'], 'weight' => 0]];
            }
        };

        $md = $this->presenter($display)->present($this->sampleEntity());

        self::assertStringContainsString('![Hero alt](https://cdn.example/img.png)', $md);
    }

    #[Test]
    public function boolean_renders_as_true_false(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity());

        self::assertMatchesRegularExpression('/## Flagged\n\ntrue\n/', $md);
    }

    #[Test]
    public function internal_fields_never_appear(): void
    {
        $entity = $this->sampleEntity([
            'two_factor_secret' => 'totp-seed',
            'password' => 'should-not-leak',
        ]);

        $md = $this->presenter($this->emptyDisplay())->present($entity);

        self::assertStringNotContainsString('two_factor_secret', $md);
        self::assertStringNotContainsString('totp-seed', $md);
        self::assertStringNotContainsString('should-not-leak', $md);
    }

    #[Test]
    public function access_filtering_omits_forbidden_fields_identically_to_serializer(): void
    {
        $entity = $this->sampleEntity(['secret_note' => 'classified']);
        $account = $this->createMock(AccountInterface::class);
        $handler = new EntityAccessHandler([$this->forbidField('article', 'secret_note')]);

        $withAccess = $this->presenter($this->emptyDisplay())->present($entity, 'full', $handler, $account);
        self::assertStringNotContainsString('classified', $withAccess);
        self::assertStringNotContainsString('## Secret Note', $withAccess);

        // Without the access handler the field is present, proving the omission
        // is caused by the access policy, not by the renderer.
        $withoutAccess = $this->presenter($this->emptyDisplay())->present($entity);
        self::assertStringContainsString('classified', $withoutAccess);
    }

    #[Test]
    public function multi_value_reference_renders_as_a_bullet_list(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity(['related' => ['1', '2']]));

        self::assertStringContainsString('- [1](/entity/1)', $md);
        self::assertStringContainsString('- [2](/entity/2)', $md);
    }

    #[Test]
    public function empty_fields_are_skipped(): void
    {
        $md = $this->presenter($this->emptyDisplay())->present($this->sampleEntity(['body' => '', 'related' => '']));

        self::assertStringNotContainsString('## Body', $md);
        self::assertStringNotContainsString('## Related', $md);
    }

    #[Test]
    public function output_is_deterministic(): void
    {
        $presenter = $this->presenter($this->emptyDisplay());
        self::assertSame(
            $presenter->present($this->sampleEntity()),
            $presenter->present($this->sampleEntity()),
        );
    }

    private function forbidField(string $entityTypeId, string $field): AccessPolicyInterface&FieldAccessPolicyInterface
    {
        return new class ($entityTypeId, $field) implements AccessPolicyInterface, FieldAccessPolicyInterface {
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $field,
            ) {}

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === $this->entityTypeId;
            }

            public function fieldAccess(EntityInterface $entity, string $fieldName, string $operation, AccountInterface $account): AccessResult
            {
                return $fieldName === $this->field ? AccessResult::forbidden() : AccessResult::neutral();
            }
        };
    }
}
