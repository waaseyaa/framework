<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase32;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Path\PathAlias;
use Waaseyaa\Path\PathAliasUniquenessListener;

/**
 * Audit-remediation batch 2026-07-02, WP3 — path_alias had ZERO Unicode
 * normalization (NFC/NFD twins of the same visible alias stored as two
 * distinct byte strings) and NO uniqueness enforcement at any layer; the
 * live JSON:API write path could create unlimited duplicate aliases.
 *
 * This test exercises the REAL save path end to end: a real SQLite-backed
 * {@see EntityRepository}, a real Symfony {@see EventDispatcher}, and
 * {@see PathAliasUniquenessListener} registered on
 * {@see BeforeSaveEvent}::class exactly as
 * {@see \Waaseyaa\Path\PathServiceProvider::boot()} wires it in a real
 * kernel boot — proving the listener is provably live, not just unit-tested
 * in isolation.
 *
 * Before this WP: every scenario below except self-exclusion (there was
 * nothing to abort) succeeded and created a duplicate row.
 */
#[CoversNothing]
final class PathAliasUniquenessTest extends TestCase
{
    private DBALDatabase $database;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        // Reuses PathAlias's own #[ContentEntityType]/#[ContentEntityKeys]/#[Field]
        // attribute metadata — the same construction PathServiceProvider::register() uses.
        $entityType = EntityType::fromClass(PathAlias::class, group: 'structure');

        new SqlSchemaHandler($entityType, $this->database)->ensureTable();

        $eventDispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($this->database);
        $database = $this->database;

        $this->repository = new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver),
            $eventDispatcher,
            database: $database,
        );

        $entityTypeManager = new EntityTypeManager(
            $eventDispatcher,
            // Mirrors the kernel's getRepository() shape (C-22 pattern, see
            // tests/Integration/PhaseN/EntityQueryAccessCheck/ListingFilterTest.php)
            // so PathAliasUniquenessListener's
            // $entityTypeManager->getRepository('path_alias') call resolves to
            // this same in-memory-sqlite-backed repository.
            repositoryFactory: static fn(string $_id, EntityTypeInterface $type): EntityRepository => new EntityRepository(
                $type,
                new SqlStorageDriver($resolver),
                $eventDispatcher,
                database: $database,
            ),
        );
        $entityTypeManager->registerEntityType($entityType);

        // The exact wiring PathServiceProvider::boot() performs in a real kernel.
        $eventDispatcher->addListener(
            BeforeSaveEvent::class,
            new PathAliasUniquenessListener($entityTypeManager),
        );
    }

    private function countRows(): int
    {
        foreach ($this->database->query('SELECT COUNT(*) AS cnt FROM path_alias') as $row) {
            return (int) $row['cnt'];
        }

        return 0;
    }

    #[Test]
    public function nfdTwinOfAnExistingAliasIsRejectedAsADuplicate(): void
    {
        // "/página" precomposed (NFC) vs. the decomposed (NFD) twin — visibly
        // identical, byte-distinct without normalization. Before this WP,
        // both saved successfully as two separate rows (first-match-wins bug).
        $nfcAlias = "/p\u{00E1}gina";
        $nfdTwin = "/pa\u{0301}gina";

        $first = $this->repository->create(['path' => '/node/1', 'alias' => $nfcAlias, 'langcode' => 'en']);
        $this->repository->save($first, validate: false);

        $second = $this->repository->create(['path' => '/node/2', 'alias' => $nfdTwin, 'langcode' => 'en']);

        $caught = null;
        try {
            $this->repository->save($second, validate: false);
        } catch (AbortOperationException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            AbortOperationException::class,
            $caught,
            'NFC-collapse + uniqueness together must reject the NFD twin of an existing alias',
        );
        $this->assertSame(1, $this->countRows(), 'only the first save must have persisted a row');
    }

    #[Test]
    public function sameAliasUnderADifferentLangcodeIsAllowed(): void
    {
        $en = $this->repository->create(['path' => '/node/1', 'alias' => '/about', 'langcode' => 'en']);
        $this->repository->save($en, validate: false);

        $fr = $this->repository->create(['path' => '/node/1', 'alias' => '/about', 'langcode' => 'fr']);

        // Must NOT throw — (alias, langcode) scoping treats a different
        // langcode as a distinct, legitimate alias (PathAliasResolver
        // matches on alias+langcode together).
        $this->repository->save($fr, validate: false);

        $this->assertSame(2, $this->countRows());
    }

    #[Test]
    public function exactDuplicateAliasSameLangcodeIsRejected(): void
    {
        $first = $this->repository->create(['path' => '/node/1', 'alias' => '/contact', 'langcode' => 'en']);
        $this->repository->save($first, validate: false);

        $second = $this->repository->create(['path' => '/node/2', 'alias' => '/contact', 'langcode' => 'en']);

        $this->expectException(AbortOperationException::class);
        $this->repository->save($second, validate: false);
    }

    #[Test]
    public function genericSetPatchPathPersistsNfcNotNfd(): void
    {
        // Simulates the JSON:API PATCH path: JsonApiController's update loop
        // applies each request attribute via the generic EntityBase::set(),
        // which bypasses PathAlias::setAlias()'s entity-side normalization.
        // The BeforeSaveEvent listener is the only chokepoint that catches this,
        // rewriting the alias to NFC before doSave() reads toArray() for the write.
        $entity = $this->repository->create(['path' => '/node/1', 'alias' => '/seed', 'langcode' => 'en']);
        $this->repository->save($entity, validate: false);

        $id = $entity->id();
        $this->assertNotNull($id);

        $loaded = $this->repository->find((string) $id);
        $this->assertInstanceOf(PathAlias::class, $loaded);

        // Raw NFD bytes written via the GENERIC setter (NOT setAlias()).
        $nfdAlias = "/pa\u{0301}ge";
        $loaded->set('alias', $nfdAlias);
        $this->assertSame($nfdAlias, $loaded->getAlias(), 'generic set() stores raw NFD pre-save');

        $this->repository->save($loaded, validate: false);

        // Assert on the RAW STORED BYTES, not a re-hydrated entity: PathAlias's
        // constructor NFC-normalizes on load (fromStorage → new PathAlias), so
        // find() would mask NFD bytes in the row. The listener's in-place
        // rewrite is what must make the persisted column itself NFC.
        $storedAlias = $this->readStoredAlias((string) $id);
        $this->assertTrue(
            \Normalizer::isNormalized($storedAlias, \Normalizer::FORM_C),
            'the STORED alias bytes must be NFC after a generic-set (PATCH-shaped) save',
        );
        $this->assertSame("/p\u{00E1}ge", $storedAlias);
    }

    /**
     * Read the raw persisted `alias` column bytes for a row, bypassing entity
     * hydration (which would re-normalize on load and mask stored NFD bytes).
     */
    private function readStoredAlias(string $id): string
    {
        foreach ($this->database->query('SELECT alias FROM path_alias WHERE id = ' . (int) $id) as $row) {
            return (string) $row['alias'];
        }

        return '';
    }

    #[Test]
    public function resavingTheSameEntityUnchangedDoesNotSelfConflict(): void
    {
        $home = $this->repository->create(['path' => '/node/1', 'alias' => '/home', 'langcode' => 'en']);
        $this->repository->save($home, validate: false);

        $id = $home->id();
        $this->assertNotNull($id, 'save() must assign an id');

        $loaded = $this->repository->find((string) $id);
        $this->assertInstanceOf(PathAlias::class, $loaded);

        // Re-saving the SAME entity (own id populated) with no changes must
        // exclude its own row from the conflict set and NOT abort.
        $this->repository->save($loaded, validate: false);

        $this->assertSame(1, $this->countRows());
    }
}
