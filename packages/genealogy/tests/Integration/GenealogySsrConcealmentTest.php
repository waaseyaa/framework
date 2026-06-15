<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\EntityAccessGate;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Genealogy\Access\GenealogyContentAccessPolicy;
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\GenealogyBootstrap;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Genealogy\Service\GenealogyFamilyService;
use Waaseyaa\Genealogy\Service\GenealogyPedigreeService;
use Waaseyaa\Genealogy\Ssr\GenealogySsrController;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\User\AnonymousUser;

/**
 * Anonymous-reachable SSR concealment for {@see GenealogySsrController}.
 *
 * Builds a real SQLite-backed app (the same harness {@see \Waaseyaa\Genealogy\Tests\Unit\GenealogyPedigreeServiceTest}
 * uses) and wires the *real* {@see GenealogyContentAccessPolicy} behind an {@see EntityAccessGate}, so the
 * controller's `$gate->allows('view', ...)` checks exercise the production OCAP rules — not a stub.
 *
 * Pins the living-person concealment invariant end-to-end through the public, unauthenticated
 * SSR surface:
 *   - a published *deceased* person under a published tree renders fully to an anonymous visitor;
 *   - a published *living* person is 404'd (never rendered) to an anonymous visitor;
 *   - the ancestor chart redacts a living ancestor while exposing the deceased subject and a deceased ancestor;
 *   - neighbor slots (parents/children/spouses) redact living relatives but expose deceased ones.
 */
#[CoversClass(GenealogySsrController::class)]
final class GenealogySsrConcealmentTest extends TestCase
{
    private EntityTypeManager $manager;

    private GenealogySsrController $controller;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();

        // The policy resolves a person/family's owning tree via the late-bound
        // EntityTypeManager. Bind the real manager so the published-tree gate is exercised.
        GenealogyBootstrap::bind($this->manager, null);

        $pedigree = new GenealogyPedigreeService($this->manager);
        $familyService = new GenealogyFamilyService($this->manager);

        $this->controller = new GenealogySsrController(
            $this->manager,
            $this->makeTwig(),
            $this->makeGate(),
            $pedigree,
            $familyService,
        );
    }

    protected function tearDown(): void
    {
        GenealogyBootstrap::reset();
        ContentEntityBase::setFieldRegistry(null);
    }

    /**
     * Anonymous visitor + published deceased person under a published tree => full render (HTTP 200).
     */
    #[Test]
    public function anonymous_person_page_renders_published_deceased_person(): void
    {
        $treeId = $this->createTree(published: true);
        $deceased = $this->createPerson('Nokomis Deceased', $treeId, isLiving: false, published: true);

        $response = $this->controller->person(
            ['id' => (string) $deceased->id()],
            [],
            new AnonymousUser(),
            $this->request(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Nokomis Deceased', (string) $response->getContent());
    }

    /**
     * Anonymous visitor + published *living* person => concealed: controller must 404, never render the profile.
     *
     * This is the load-bearing privacy invariant: a living person is not viewable anonymously even when
     * both the person row and its tree are published.
     */
    #[Test]
    public function anonymous_person_page_conceals_living_person(): void
    {
        $treeId = $this->createTree(published: true);
        $living = $this->createPerson('Living Secret', $treeId, isLiving: true, published: true);

        $response = $this->controller->person(
            ['id' => (string) $living->id()],
            [],
            new AnonymousUser(),
            $this->request(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Living Secret', (string) $response->getContent());
    }

    /**
     * Neighbor slots on the person page redact a living parent but expose a deceased parent.
     */
    #[Test]
    public function anonymous_person_page_redacts_living_neighbor_but_shows_deceased_neighbor(): void
    {
        $treeId = $this->createTree(published: true);

        $subject = $this->createPerson('Deceased Subject', $treeId, isLiving: false, published: true);
        $livingParent = $this->createPerson('Living Parent', $treeId, isLiving: true, published: true);
        $deceasedParent = $this->createPerson('Deceased Parent', $treeId, isLiving: false, published: true);

        $this->createParentEdge((string) $livingParent->id(), (string) $subject->id());
        $this->createParentEdge((string) $deceasedParent->id(), (string) $subject->id());

        $response = $this->controller->person(
            ['id' => (string) $subject->id()],
            [],
            new AnonymousUser(),
            $this->request(),
        );

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        // Deceased neighbor is exposed by name.
        self::assertStringContainsString('Deceased Parent', $html);
        // Living neighbor is redacted: name never leaks, redaction placeholder is shown instead.
        self::assertStringNotContainsString('Living Parent', $html);
        self::assertStringContainsString('Private living relative', $html);
    }

    /**
     * Ancestor chart: deceased subject + deceased grandparent are exposed; a living parent between them
     * is redacted. Enforces concealment on the redacted pedigree projection.
     */
    #[Test]
    public function anonymous_ancestor_chart_redacts_living_ancestor_and_shows_deceased(): void
    {
        $treeId = $this->createTree(published: true);

        $subject = $this->createPerson('Chart Subject Deceased', $treeId, isLiving: false, published: true);
        $livingParent = $this->createPerson('Living Mother', $treeId, isLiving: true, published: true);
        $deceasedGrandparent = $this->createPerson('Deceased Grandmother', $treeId, isLiving: false, published: true);

        $this->createParentEdge((string) $livingParent->id(), (string) $subject->id());
        $this->createParentEdge((string) $deceasedGrandparent->id(), (string) $livingParent->id());

        $response = $this->controller->ancestorChart(
            ['id' => (string) $subject->id()],
            [],
            new AnonymousUser(),
            $this->request(),
        );

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        // Subject (gen 0, deceased) and grandparent (gen 2, deceased) are visible by name.
        self::assertStringContainsString('Chart Subject Deceased', $html);
        self::assertStringContainsString('Deceased Grandmother', $html);

        // The living parent (gen 1) is redacted: name withheld, placeholder rendered.
        self::assertStringNotContainsString('Living Mother', $html);
        self::assertStringContainsString('Private living relative', $html);
    }

    /**
     * Anonymous visitor + unpublished tree => the person under it is not viewable, even if deceased.
     * Confirms the gate (not just the living axis) is genuinely consulted through the controller.
     */
    #[Test]
    public function anonymous_person_page_404s_when_tree_unpublished(): void
    {
        $treeId = $this->createTree(published: false);
        $deceased = $this->createPerson('Hidden Under Private Tree', $treeId, isLiving: false, published: true);

        $response = $this->controller->person(
            ['id' => (string) $deceased->id()],
            [],
            new AnonymousUser(),
            $this->request(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Hidden Under Private Tree', (string) $response->getContent());
    }

    private function makeManager(): EntityTypeManager
    {
        EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();

        $manager = new EntityTypeManager(
            $dispatcher,
            function (EntityTypeInterface $definition) use ($database, $dispatcher, $registry): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();

                return new SqlEntityStorage($definition, $database, $dispatcher, $registry);
            },
            fieldRegistry: $registry,
        );

        ContentEntityBase::setFieldRegistry($registry);

        $manager->registerEntityType(TestEntityType::stub(
            id: 'relationship',
            class: Relationship::class,
            keys: [
                'id' => 'rid',
                'uuid' => 'uuid',
                'label' => 'relationship_type',
                'bundle' => 'relationship_type',
            ],
            label: 'Relationship',
            fieldDefinitions: [
                'relationship_type' => ['type' => 'string', 'required' => true, 'weight' => 0],
                'from_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 1],
                'from_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 2],
                'to_entity_type' => ['type' => 'string', 'required' => true, 'weight' => 3],
                'to_entity_id' => ['type' => 'string', 'required' => true, 'weight' => 4],
                'directionality' => ['type' => 'string', 'weight' => 5, 'default' => 'directed'],
                'status' => ['type' => 'boolean', 'weight' => 6, 'default' => 1],
            ],
        ));

        $manager->registerEntityType(EntityType::fromClass(GenealogyTree::class, group: 'content'));
        $manager->registerEntityType(EntityType::fromClass(GenealogyPerson::class, group: 'content'));
        $manager->registerEntityType(EntityType::fromClass(GenealogyFamily::class, group: 'content'));

        return $manager;
    }

    /**
     * Real policy behind the production gate adapter, so controller `view` checks are genuine OCAP checks.
     */
    private function makeGate(): GateInterface
    {
        $handler = new EntityAccessHandler([new GenealogyContentAccessPolicy()]);

        return new EntityAccessGate($handler);
    }

    private function makeTwig(): Environment
    {
        // Single root resolves both the top-level `genealogy_person.html.twig` and the
        // `genealogy/`-namespaced partials/layout the controller's templates reference.
        $templates = dirname(__DIR__, 2) . '/templates';

        return new Environment(new FilesystemLoader($templates));
    }

    private function request(): Request
    {
        return Request::create('/genealogy', 'GET');
    }

    private function createTree(bool $published): string
    {
        $storage = $this->manager->getStorage('genealogy_tree');
        $tree = $storage->create([
            'display_name' => 'Fixture tree',
            'owner_uid' => 99,
            'status' => $published,
        ]);
        $storage->save($tree);

        return (string) $tree->id();
    }

    private function createPerson(string $displayName, string $treeId, bool $isLiving, bool $published): GenealogyPerson
    {
        $storage = $this->manager->getStorage('genealogy_person');
        $person = $storage->create([
            'display_name' => $displayName,
            'tree_id' => (int) $treeId,
            'is_living' => $isLiving,
            'status' => $published,
        ]);
        $storage->save($person);

        self::assertInstanceOf(GenealogyPerson::class, $person);

        return $person;
    }

    private function createParentEdge(string $parentId, string $childId): void
    {
        $storage = $this->manager->getStorage('relationship');
        $edge = $storage->create([
            'relationship_type' => GenealogyRelationshipType::PARENT_OF,
            'from_entity_type' => 'genealogy_person',
            'from_entity_id' => $parentId,
            'to_entity_type' => 'genealogy_person',
            'to_entity_id' => $childId,
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $storage->save($edge);
    }
}
