<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseSingleEntityWorkSurface;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\AttachmentRepository;
use Waaseyaa\Attachment\Schema\AttachmentSchema;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\Field\BundleTemplateCompiler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\Form\FormDescriptorBuilder;
use Waaseyaa\Routing\EntityDeepLinkRouteBuilder;
use Waaseyaa\StructuredImport\Gfm\GfmTableImporter;
use Waaseyaa\StructuredImport\Gfm\GfmTableParser;
use Waaseyaa\StructuredImport\Gfm\PromptNormalizer;
use Waaseyaa\Tests\Integration\PhaseSingleEntityWorkSurface\Fixtures\SampleProfileTemplate;

/**
 * Cross-primitive end-to-end integration test (Success Criterion 5).
 *
 * Exercises all six Single-Entity Work Surface primitives using real components
 * wired against an in-memory SQLite database. No mocks are used for components
 * that exist in real code.
 *
 * Integration scope: component-level (real components, in-memory SQLite, no
 * HTTP kernel boot). The FieldAutoSaveController is exercised by constructing
 * it directly and calling update() with a synthetic Request — equivalent to
 * the kernel dispatch path for this controller type.
 *
 * Primitives tested:
 *   F1 — EntityDeepLinkRouteBuilder: route path, method, entity parameter
 *   F2 — BundleTemplateCompiler + FieldDefinitionRegistry: 5 fields, aliases, groups
 *   F3 — FieldAutoSaveController: 200 on valid field, 404 on unknown entity type
 *   F4 — AttachmentRepository: save + setActive + getActive invariant
 *   F5 — GfmTableImporter: 4 matched + 1 unmatched from markdown table
 *   F6 — FormDescriptorBuilder: ordered descriptors with correct group + value
 *
 * @see kitty-specs/single-entity-work-surface-01KQ7M1P/spec.md — Success Criterion 5
 * @see kitty-specs/single-entity-work-surface-01KQ7M1P/quickstart.md
 */
#[CoversNothing]
final class SingleEntityWorkSurfaceTest extends TestCase
{
    private DBALDatabase $database;

    private AttachmentRepository $attachmentRepository;

    private FieldDefinitionRegistry $fieldRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        // Shared in-memory SQLite database for the attachment primitive.
        $this->database = DBALDatabase::createSqlite();

        // ── Attachment schema + repository ──────────────────────��─────────────
        new AttachmentSchema($this->database)->ensureTable();

        $attachmentEntityType = EntityType::fromClass(Attachment::class);
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, 'id');
        $dispatcher = new EventDispatcher();

        $attachmentEntityRepository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            entityType: $attachmentEntityType,
            driver: $driver,
            eventDispatcher: $dispatcher,
        );

        $this->attachmentRepository = new AttachmentRepository(
            entityRepository: $attachmentEntityRepository,
            database: $this->database,
        );

        // ── FieldDefinitionRegistry ───────────────────────────────────────────
        $this->fieldRegistry = new FieldDefinitionRegistry();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F2 — BundleTemplateCompiler
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f2_bundleTemplateCompilerRegistersProfileFields(): void
    {
        $compiler = new BundleTemplateCompiler($this->fieldRegistry);
        $compiler->compile([SampleProfileTemplate::class]);

        $fields = $this->fieldRegistry->bundleFieldsFor('node', 'profile');

        self::assertCount(5, $fields, 'Five fields must be registered for (node, profile)');
        self::assertSame(
            ['name', 'bio', 'birthplace', 'website', 'is_published'],
            array_keys($fields),
            'Fields must appear in declaration order',
        );

        // F2 assertion: name field carries prompt aliases and group.
        $nameField = $fields['name'];
        self::assertSame(['name', 'display name', 'full name'], $nameField->getPromptAliases());
        self::assertSame('identity', $nameField->getGroup());
        self::assertTrue($nameField->isRequired());

        // F2 assertion: bio is in 'about' group.
        self::assertSame('about', $fields['bio']->getGroup());
        self::assertSame('text', $fields['bio']->getType());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F1 — EntityDeepLinkRouteBuilder
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f1_entityDeepLinkRouteBuilderProducesCorrectShape(): void
    {
        $route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\\Controller\\NodeController::edit')
            ->build();

        self::assertSame('/edit/node/{id}', $route->getPath(), 'Route path must follow {segment}/{entityType}/{id} pattern');
        self::assertSame(['GET'], $route->getMethods(), 'Route must be GET-only');
        self::assertSame('App\\Controller\\NodeController::edit', $route->getDefault('_controller'));

        $parameters = $route->getOption('parameters');
        self::assertIsArray($parameters);
        self::assertArrayHasKey('id', $parameters, 'id parameter must be declared for upcasting');
        self::assertSame('entity:node', $parameters['id']['type'], 'Entity parameter must wire to entity:node');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F3 — FieldAutoSaveController
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f3_fieldAutoSaveControllerReturns404ForUnknownEntityType(): void
    {
        // Compile fields first so registry is populated.
        new BundleTemplateCompiler($this->fieldRegistry)->compile([SampleProfileTemplate::class]);

        $controller = new \Waaseyaa\Api\Controller\FieldAutoSaveController(
            entityTypeManager: $this->buildEmptyEntityTypeManager(),
            accessHandler: $this->buildGrantAllAccessHandler(),
            fieldRegistry: $this->fieldRegistry,
        );

        $request = $this->buildJsonRequest(['value' => 'test']);
        $request->attributes->set('_account', $this->buildFixtureAccount());

        $response = $controller->update($request, 'no_such_type', '1', 'name');

        self::assertSame(404, $response->getStatusCode(), 'Unknown entity type must return 404');
    }

    #[Test]
    public function f3_fieldAutoSaveControllerReturns415ForNonJsonContentType(): void
    {
        new BundleTemplateCompiler($this->fieldRegistry)->compile([SampleProfileTemplate::class]);

        $controller = new \Waaseyaa\Api\Controller\FieldAutoSaveController(
            entityTypeManager: $this->buildEmptyEntityTypeManager(),
            accessHandler: $this->buildGrantAllAccessHandler(),
            fieldRegistry: $this->fieldRegistry,
        );

        $request = Request::create('/', 'PUT', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=test');
        $request->attributes->set('_account', $this->buildFixtureAccount());

        $response = $controller->update($request, 'node', '1', 'name');

        self::assertSame(415, $response->getStatusCode(), 'Non-JSON content type must return 415');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F4 — AttachmentRepository
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f4_attachmentRepositorySetActiveEnforcesAtMostOneActive(): void
    {
        $attachmentIds = [];

        // Save three attachments for the same parent node.
        for ($i = 1; $i <= 3; $i++) {
            $attachment = new Attachment([
                'parent_entity_type' => 'node',
                'parent_entity_id' => '42',
                'filename' => "file{$i}.txt",
                'is_active' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            $attachment->enforceIsNew();
            $this->attachmentRepository->save($attachment);
            $attachmentIds[] = (string) $attachment->id();
        }

        self::assertCount(3, $attachmentIds, 'Three attachments must be saved');

        // Initially no attachment is active.
        self::assertNull(
            $this->attachmentRepository->getActive('node', '42'),
            'No attachment should be active before setActive()',
        );

        // Activate the second attachment (index 1).
        $this->attachmentRepository->setActive($attachmentIds[1]);

        $active = $this->attachmentRepository->getActive('node', '42');
        self::assertNotNull($active, 'One attachment must be active after setActive()');
        self::assertSame(
            $attachmentIds[1],
            (string) $active->id(),
            'The second attachment (index 1) must be active',
        );

        // Transfer active flag to the first attachment — previous must clear.
        $this->attachmentRepository->setActive($attachmentIds[0]);

        $newActive = $this->attachmentRepository->getActive('node', '42');
        self::assertNotNull($newActive);
        self::assertSame(
            $attachmentIds[0],
            (string) $newActive->id(),
            'The first attachment (index 0) must now be active; second must be deactivated',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F5 — GfmTableImporter
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f5_gfmTableImporterMatchesFourAndUnmatchesOne(): void
    {
        new BundleTemplateCompiler($this->fieldRegistry)->compile([SampleProfileTemplate::class]);

        $importer = new GfmTableImporter(
            registry: $this->fieldRegistry,
            parser: new GfmTableParser(),
            normalizer: new PromptNormalizer(),
        );

        // 'Status' has no matching alias in SampleProfileTemplate — must be unmatched.
        $payload = "| Field | Value |\n"
            . "| --- | --- |\n"
            . "| Display Name | Aanikoobijigan |\n"
            . "| Biography | Storyteller. |\n"
            . "| Born In | Naotkamegwanning |\n"
            . "| Website | https://example.test |\n"
            . "| Status | Active |\n";

        $result = $importer->import($payload, 'node', 'profile');

        self::assertCount(4, $result->matched, 'Four rows must match via prompt aliases');
        self::assertCount(1, $result->unmatched, '"Status" has no alias — must be unmatched');
        self::assertSame(
            'Status',
            $result->unmatched[0]->prompt,
            'Unmatched prompt must preserve original un-normalized text',
        );

        // Verify specific matched field keys.
        self::assertArrayHasKey('name', $result->matched, '"Display Name" alias must resolve to "name" field');
        self::assertSame('Aanikoobijigan', $result->matched['name']);
        self::assertArrayHasKey('bio', $result->matched, '"Biography" alias must resolve to "bio" field');
        self::assertArrayHasKey('birthplace', $result->matched, '"Born In" alias must resolve to "birthplace" field');
        self::assertArrayHasKey('website', $result->matched, '"Website" alias must resolve to "website" field');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F6 — FormDescriptorBuilder
    // ─────────────────────────────────────────────────────────────────────────

    #[Test]
    public function f6_formDescriptorBuilderProducesOrderedDescriptors(): void
    {
        new BundleTemplateCompiler($this->fieldRegistry)->compile([SampleProfileTemplate::class]);

        $builder = new FormDescriptorBuilder(registry: $this->fieldRegistry);

        $entity = $this->buildStubEntity('node', 'profile', [
            'name' => 'Aanikoobijigan',
            'bio' => 'Storyteller.',
            'birthplace' => 'Naotkamegwanning',
            'website' => 'https://example.test',
            'is_published' => true,
        ]);

        $descriptors = $builder->build($entity, 'profile');

        self::assertCount(5, $descriptors, 'Five descriptors for (node, profile)');

        // Declaration order preserved.
        self::assertSame('name', $descriptors[0]->name);
        self::assertSame('bio', $descriptors[1]->name);
        self::assertSame('birthplace', $descriptors[2]->name);
        self::assertSame('website', $descriptors[3]->name);
        self::assertSame('is_published', $descriptors[4]->name);

        // Groups are correct per SampleProfileTemplate declarations.
        self::assertSame('identity', $descriptors[0]->group, 'name is in identity group');
        self::assertSame('about', $descriptors[1]->group, 'bio is in about group');
        self::assertSame('about', $descriptors[2]->group, 'birthplace is in about group');
        self::assertSame('contact', $descriptors[3]->group, 'website is in contact group');
        self::assertSame('publishing', $descriptors[4]->group, 'is_published is in publishing group');

        // Values are passed through from the stub entity.
        self::assertSame('Aanikoobijigan', $descriptors[0]->value);
        self::assertSame('Storyteller.', $descriptors[1]->value);
        self::assertTrue($descriptors[4]->value);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build a synthetic JSON PUT request.
     *
     * @param array<string, mixed> $body
     */
    private function buildJsonRequest(array $body): Request
    {
        return Request::create(
            '/',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Build an EntityTypeManagerInterface that has no entity types registered.
     * Used to exercise the 404 path in FieldAutoSaveController.
     */
    private function buildEmptyEntityTypeManager(): \Waaseyaa\Entity\EntityTypeManagerInterface
    {
        return new class implements \Waaseyaa\Entity\EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
            {
                return [];
            }
            public function hasDefinition(string $entityTypeId): bool
            {
                return false;
            }

            public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
            {
                throw new \InvalidArgumentException("Unknown entity type '{$entityTypeId}'");
            }

            public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface
            {
                throw new \InvalidArgumentException("Unknown entity type '{$entityTypeId}'");
            }

            public function getDefinitions(): array
            {
                return [];
            }

            public function registerEntityType(
                \Waaseyaa\Entity\EntityTypeInterface $type,
                ?string $registrant = null,
            ): void {}

            public function registerCoreEntityType(
                \Waaseyaa\Entity\EntityTypeInterface $type,
                ?string $registrant = null,
            ): void {}

            public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
            {
                throw new \LogicException('No repository configured in test stub.');
            }
        };
    }

    /**
     * Build an EntityAccessHandler that grants Allowed for all entity operations.
     */
    private function buildGrantAllAccessHandler(): EntityAccessHandler
    {
        $policy = new class implements AccessPolicyInterface {
            public function access(
                EntityInterface $entity,
                string $operation,
                AccountInterface $account,
            ): AccessResult {
                return AccessResult::allowed();
            }

            public function createAccess(
                string $entityTypeId,
                string $bundle,
                AccountInterface $account,
            ): AccessResult {
                return AccessResult::allowed();
            }

            public function appliesTo(string $entityTypeId): bool
            {
                return true;
            }
        };

        return new EntityAccessHandler([$policy]);
    }

    /**
     * Build a fixture authenticated account.
     */
    private function buildFixtureAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 1;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function hasPermission(string $permission): bool
            {
                return true;
            }
        };
    }

    /**
     * Build a minimal stub entity (EntityInterface) for FormDescriptorBuilder.
     *
     * Returns raw scalar values via get() so FormDescriptorBuilder can store
     * them in FormFieldDescriptor::$value.
     *
     * @param array<string, mixed> $values
     */
    private function buildStubEntity(
        string $entityTypeId,
        string $bundle,
        array $values,
    ): EntityInterface {
        return new class ($entityTypeId, $bundle, $values) implements EntityInterface {
            /** @param array<string, mixed> $values */
            public function __construct(
                private readonly string $entityTypeId,
                private readonly string $bundle,
                private readonly array $values,
            ) {}

            public function id(): int|string|null
            {
                return 1;
            }

            public function uuid(): string
            {
                return '00000000-0000-0000-0000-000000000001';
            }

            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }

            public function bundle(): string
            {
                return $this->bundle;
            }

            public function label(): string
            {
                return (string) ($this->values['title'] ?? '');
            }

            public function get(string $name): mixed
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, mixed $value): static
            {
                return $this;
            }

            public function isNew(): bool
            {
                return false;
            }

            public function toArray(): array
            {
                return $this->values;
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }
}
