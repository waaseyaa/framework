<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit\Trail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Wayfinding\Entity\Trail;
use Waaseyaa\Wayfinding\Trail\TrailStore;

/**
 * Acceptance gate for Wayfinding Phase 4 — trail persistence (SC-005).
 *
 * Drives {@see TrailStore} against the REAL {@see Trail} entity type (resolved
 * from its attributes via {@see EntityType::fromClass()}) over a real two-axis
 * (revisionable + translatable) SQLite store, proving:
 *   - a live trail records to a versioned, human-owned saved entity (FR-009/FR-010);
 *   - re-recording over a human-owned trail creates a new revision and the prior
 *     human edits SURVIVE untouched (FR-011 — the no-silent-overwrite rule);
 *   - re-recording an agent-recorded trail safely advances the live value;
 *   - en and fr are versioned independently (FR-009 translatable axis).
 */
#[CoversClass(TrailStore::class)]
final class TrailStoreTest extends TestCase
{
    private TrailStore $store;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite();

        $entityType = EntityType::fromClass(
            Trail::class,
            revisionable: true,
            translatable: true,
            group: 'content',
        );

        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureTranslationRevisionTable();

        $resolver = new SingleConnectionResolver($db);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        // Mirror the kernel: the SQL driver needs the entity's id key ('tid'),
        // not the 'id' default (AbstractKernel::bootEntityTypeManager).
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver, $entityType->getKeys()['id']),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );

        $this->store = new TrailStore($repository);
    }

    /**
     * @param list<array{anchor_id: string, content: string, order: int}> ...
     * @return array{anchor_id: string, content: string, order: int}
     */
    private static function beacon(string $anchorId, string $content, int $order): array
    {
        return ['anchor_id' => $anchorId, 'content' => $content, 'order' => $order];
    }

    #[Test]
    public function record_creates_a_versioned_human_owned_trail(): void
    {
        $saved = $this->store->record('en', 'Onboarding', [
            self::beacon('field:node:title', 'Edit the title', 0),
            self::beacon('action:node:submit', 'Now save', 1),
        ], ownerUid: 7);

        $this->assertNotSame('', $saved->id);
        $this->assertSame('en', $saved->langcode);
        $this->assertSame('Onboarding', $saved->title);
        $this->assertSame(7, $saved->ownerUid);
        $this->assertSame(Trail::ORIGIN_RECORDED, $saved->origin);
        $this->assertCount(2, $saved->beacons);
        $this->assertSame('field:node:title', $saved->beacons[0]['anchor_id']);
        $this->assertSame('Now save', $saved->beacons[1]['content']);
        $this->assertSame(1, $saved->beacons[1]['order']);

        // It reads back as the live value of its language.
        $current = $this->store->current($saved->id, 'en');
        $this->assertNotNull($current);
        $this->assertSame('Onboarding', $current->title);
        $this->assertSame(7, $current->ownerUid);
    }

    #[Test]
    public function re_recording_a_human_owned_trail_never_overwrites_edits(): void
    {
        $id = $this->store->record('en', 'Recorded', [self::beacon('a', 'recorded one', 0)], ownerUid: 5)->id;

        // The owner edits the trail — it becomes human-owned.
        $this->store->editAsHuman($id, 'en', 'Human title', [
            self::beacon('a', 'human one', 0),
            self::beacon('b', 'human two', 1),
        ]);
        $afterEdit = $this->store->current($id, 'en');
        $this->assertNotNull($afterEdit);
        $this->assertSame('Human title', $afterEdit->title);
        $this->assertSame(Trail::ORIGIN_HUMAN, $afterEdit->origin);

        $revsBeforeReRecord = $this->store->revisionCount($id, 'en');

        // An agent re-records the live trail over the human-owned one.
        $result = $this->store->reRecord($id, 'en', 'Agent re-record', [self::beacon('a', 'agent one', 0)]);

        // It landed as a DRAFT, not promoted — the live value was human-owned.
        $this->assertFalse($result->promoted);

        // The human's live trail SURVIVES, byte-for-byte (FR-011 / SC-005).
        $afterReRecord = $this->store->current($id, 'en');
        $this->assertNotNull($afterReRecord);
        $this->assertSame('Human title', $afterReRecord->title);
        $this->assertSame(Trail::ORIGIN_HUMAN, $afterReRecord->origin);
        $this->assertCount(2, $afterReRecord->beacons);
        $this->assertSame('human one', $afterReRecord->beacons[0]['content']);

        // A NEW revision was created, and the re-recorded draft is recoverable.
        $this->assertSame($revsBeforeReRecord + 1, $this->store->revisionCount($id, 'en'));
        $draft = $this->store->latestRevision($id, 'en');
        $this->assertNotNull($draft);
        $this->assertSame('Agent re-record', $draft->title);
        $this->assertSame('agent one', $draft->beacons[0]['content']);
    }

    #[Test]
    public function re_recording_an_agent_recorded_trail_advances_the_live_value(): void
    {
        $id = $this->store->record('en', 'v1', [self::beacon('a', 'one', 0)], ownerUid: 5)->id;

        // No human edits yet — re-recording is safe to promote.
        $result = $this->store->reRecord($id, 'en', 'v2', [self::beacon('a', 'two', 0)]);
        $this->assertTrue($result->promoted);

        $current = $this->store->current($id, 'en');
        $this->assertNotNull($current);
        $this->assertSame('v2', $current->title);
        $this->assertSame('two', $current->beacons[0]['content']);
        $this->assertSame(Trail::ORIGIN_RECORDED, $current->origin);
    }

    #[Test]
    public function languages_are_versioned_independently(): void
    {
        $id = $this->store->record('en', 'English', [self::beacon('a', 'en beacon', 0)], ownerUid: 9)->id;

        // The owner edits English, then authors and edits a French translation.
        $this->store->editAsHuman($id, 'en', 'English edited', [self::beacon('a', 'en beacon v2', 0)]);
        $this->store->editAsHuman($id, 'fr', 'Français', [self::beacon('a', 'balise fr', 0)]);
        $this->store->editAsHuman($id, 'fr', 'Français v2', [self::beacon('a', 'balise fr v2', 0)]);

        $en = $this->store->current($id, 'en');
        $fr = $this->store->current($id, 'fr');
        $this->assertNotNull($en);
        $this->assertNotNull($fr);

        // Each language has its own live value — editing fr never touched en.
        $this->assertSame('English edited', $en->title);
        $this->assertSame('en beacon v2', $en->beacons[0]['content']);
        $this->assertSame('Français v2', $fr->title);
        $this->assertSame('balise fr v2', $fr->beacons[0]['content']);

        // …and its own independent revision sequence (en: 1 edit, fr: 2 edits).
        $this->assertSame(1, $this->store->revisionCount($id, 'en'));
        $this->assertSame(2, $this->store->revisionCount($id, 'fr'));

        // An untranslated language is simply absent.
        $this->assertNull($this->store->current($id, 'oj'));
        $this->assertSame(0, $this->store->revisionCount($id, 'oj'));
    }
}
