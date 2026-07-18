<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Genealogy\Access\GenealogyContentAccessPolicy;
use Waaseyaa\Genealogy\Entity\GenealogyEvent;
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\GenealogyBootstrap;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\SSR\Http\SeoPublicController;

/**
 * R6 PR3 (audit M3) — genealogy_person handling for the public SEO surface.
 *
 * The task's remediation requires the access-aware sitemap/llms.txt
 * enumeration ({@see SeoPublicController}, `Waaseyaa\Seo\SitemapGenerator`,
 * `Waaseyaa\Seo\Llms\LlmsTxtGenerator`) to correctly exclude a restricted
 * `genealogy_person` rather than adding the type to
 * `SeoPublicController::NON_PUBLIC_TYPES` wholesale. This test proves the
 * mechanism: binding an anonymous account via `setAccount()` on the
 * enumeration query runs the SAME real {@see GenealogyContentAccessPolicy}
 * (OCAP) the rest of the framework uses, so:
 *
 * - a published, deceased person under a published tree IS enumerated
 *   (positive control — the type is not blanket-excluded);
 * - a published, LIVING person under a published tree is NOT enumerated
 *   (living-person redaction applies even to otherwise-public rows); and
 * - a published, deceased person under an UNPUBLISHED tree is NOT
 *   enumerated (the tree-visibility gate is genuinely consulted, not just
 *   the person's own `status` field).
 */
#[CoversClass(SeoPublicController::class)]
final class GenealogySeoEnumerationTest extends TestCase
{
    private EntityTypeManager $manager;

    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        $this->manager = $this->makeManager();
        GenealogyBootstrap::bind($this->manager, null);
        $this->accessHandler = new EntityAccessHandler([new GenealogyContentAccessPolicy()]);
    }

    protected function tearDown(): void
    {
        GenealogyBootstrap::reset();
        ContentEntityBase::setFieldRegistry(null);
    }

    #[Test]
    public function sitemap_excludes_restricted_genealogy_persons_but_includes_the_public_one(): void
    {
        $publicTree = $this->createTree(published: true);
        $privateTree = $this->createTree(published: false);

        $publicDeceased = $this->createPerson('Nokomis Deceased', $publicTree, isLiving: false, published: true);
        $livingUnderPublicTree = $this->createPerson('Living Secret', $publicTree, isLiving: true, published: true);
        $deceasedUnderPrivateTree = $this->createPerson('Hidden Ancestor', $privateTree, isLiving: false, published: true);

        $controller = new SeoPublicController($this->manager);
        $body = (string) $controller->sitemapXml()->getContent();

        self::assertStringContainsString(
            '/genealogy_person/' . $publicDeceased->id(),
            $body,
            'a published deceased person under a published tree must be enumerated (positive control)',
        );
        self::assertStringNotContainsString(
            '/genealogy_person/' . $livingUnderPublicTree->id(),
            $body,
            'a published LIVING person must not be enumerated (OCAP living-person redaction)',
        );
        self::assertStringNotContainsString(
            '/genealogy_person/' . $deceasedUnderPrivateTree->id(),
            $body,
            'a person under an unpublished tree must not be enumerated',
        );
    }

    #[Test]
    public function llms_txt_excludes_restricted_genealogy_persons_but_includes_the_public_one(): void
    {
        $publicTree = $this->createTree(published: true);
        $privateTree = $this->createTree(published: false);

        $publicDeceased = $this->createPerson('Nokomis Deceased', $publicTree, isLiving: false, published: true);
        $livingUnderPublicTree = $this->createPerson('Living Secret', $publicTree, isLiving: true, published: true);
        $deceasedUnderPrivateTree = $this->createPerson('Hidden Ancestor', $privateTree, isLiving: false, published: true);

        $controller = new SeoPublicController($this->manager);
        $body = (string) $controller->llmsTxt()->getContent();

        self::assertStringContainsString('/genealogy_person/' . $publicDeceased->id(), $body);
        self::assertStringNotContainsString('/genealogy_person/' . $livingUnderPublicTree->id(), $body);
        self::assertStringNotContainsString('/genealogy_person/' . $deceasedUnderPrivateTree->id(), $body);
    }

    #[Test]
    public function sitemap_and_llms_never_enumerate_genealogy_family_or_event(): void
    {
        // genealogy m-a: family/event carry a free-text display_name that names
        // living people with no per-row living/deceased axis, so they are
        // excluded from the crawler surface wholesale (SeoPublicController::
        // NON_PUBLIC_TYPES) rather than access-filtered like genealogy_person.
        $publicTree = $this->createTree(published: true);
        $family = $this->manager->getRepository('genealogy_family')->create([
            'display_name' => 'Wedding of Living Alice',
            'tree_id' => (int) $publicTree,
            'status' => true,
        ]);
        $this->manager->getRepository('genealogy_family')->save($family);
        $event = $this->manager->getRepository('genealogy_event')->create([
            'display_name' => 'Baptism of Living Bob',
            'tree_id' => (int) $publicTree,
            'status' => true,
        ]);
        $this->manager->getRepository('genealogy_event')->save($event);

        $controller = new SeoPublicController($this->manager);
        $sitemap = (string) $controller->sitemapXml()->getContent();
        $llms = (string) $controller->llmsTxt()->getContent();

        foreach ([$sitemap, $llms] as $body) {
            self::assertStringNotContainsString('/genealogy_family/', $body, 'family must never be crawler-enumerated');
            self::assertStringNotContainsString('/genealogy_event/', $body, 'event must never be crawler-enumerated');
        }
    }

    private function makeManager(): EntityTypeManager
    {
        \Waaseyaa\Entity\EntityType::clearFromClassCache();
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        $dispatcher = new EventDispatcher();
        $registry = new FieldDefinitionRegistry();
        $resolver = new SingleConnectionResolver($database);

        $manager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $resolver, $database, $registry): EntityRepository {
                (new SqlSchemaHandler($definition, $database, $registry))->ensureTable();
                $idKey = $definition->getKeys()['id'] ?? 'id';

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver($resolver, $idKey),
                    $dispatcher,
                    database: $database,
                    fieldRegistry: $registry,
                    accessHandlerResolver: fn(): ?EntityAccessHandler => $this->accessHandler ?? null,
                );
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

        $manager->registerEntityType(\Waaseyaa\Entity\EntityType::fromClass(GenealogyTree::class, group: 'content'));
        $manager->registerEntityType(\Waaseyaa\Entity\EntityType::fromClass(GenealogyPerson::class, group: 'content'));
        $manager->registerEntityType(\Waaseyaa\Entity\EntityType::fromClass(GenealogyFamily::class, group: 'content'));
        $manager->registerEntityType(\Waaseyaa\Entity\EntityType::fromClass(GenealogyEvent::class, group: 'content'));

        return $manager;
    }

    private function createTree(bool $published): string
    {
        $storage = $this->manager->getRepository('genealogy_tree');
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
        $storage = $this->manager->getRepository('genealogy_person');
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
}
