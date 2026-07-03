<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Tool\Wayfinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Agent\Tests\Support\FakeEntityTypeManager;
use Waaseyaa\AI\Agent\Tool\Wayfinding\EditTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\GetTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\RecordTrailTool;
use Waaseyaa\AI\Agent\Tool\Wayfinding\ReRecordTrailTool;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Wayfinding\Access\TrailAccessPolicy;
use Waaseyaa\Wayfinding\Entity\Trail;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;
use Waaseyaa\Wayfinding\Trail\TrailStore;

/**
 * Acceptance for the Wayfinding saved-trail MCP write tools (Phase 5): they are
 * capability-gated (`present guided content`, fail-closed — FR-003/NFR-002/SC-002)
 * and correctly drive the Phase-4 {@see TrailStore}, including the
 * no-silent-overwrite rule (re-recording a human-owned trail lands a draft).
 */
#[CoversClass(RecordTrailTool::class)]
#[CoversClass(ReRecordTrailTool::class)]
#[CoversClass(GetTrailTool::class)]
#[CoversClass(EditTrailTool::class)]
final class WayfindingTrailToolsTest extends TestCase
{
    private EntityRepository $repository;
    private FakeEntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->repository = $this->buildTrailRepository();
        $this->entityTypeManager = new FakeEntityTypeManager(['wayfinding_trail' => $this->repository]);
    }

    #[Test]
    public function record_is_forbidden_without_the_capability(): void
    {
        $tool = new RecordTrailTool($this->entityTypeManager);
        $result = $tool->execute(
            ['title' => 'x', 'beacons' => [['anchor_id' => 'a', 'content' => 'b']]],
            $this->account(hasCapability: false),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function record_then_get_round_trips_a_trail(): void
    {
        $account = $this->account(hasCapability: true);

        $recorded = new RecordTrailTool($this->entityTypeManager)->execute([
            'title' => 'Onboarding',
            'langcode' => 'en',
            'beacons' => [
                ['anchor_id' => 'field:node:title', 'content' => 'Edit the title', 'order' => 0],
                ['anchor_id' => 'action:node:submit', 'content' => 'Now save', 'order' => 1],
            ],
        ], $account);

        self::assertFalse($recorded->isError);
        $data = $recorded->content[0]['data'];
        self::assertSame('Onboarding', $data['title']);
        self::assertSame(42, $data['owner_uid']);
        self::assertSame('recorded', $data['origin']);
        self::assertSame(2, $data['beacon_count']);

        $got = new GetTrailTool($this->entityTypeManager)->execute(
            ['trail_id' => $data['trail_id'], 'langcode' => 'en'],
            $account,
        );
        self::assertFalse($got->isError);
        self::assertSame('Onboarding', $got->content[0]['data']['title']);
        self::assertCount(2, $got->content[0]['data']['beacons']);
        self::assertSame('Now save', $got->content[0]['data']['beacons'][1]['content']);
    }

    #[Test]
    public function rerecording_a_human_owned_trail_lands_a_draft_and_preserves_edits(): void
    {
        $account = $this->account(hasCapability: true);

        $recorded = new RecordTrailTool($this->entityTypeManager)->execute([
            'title' => 'Recorded',
            'beacons' => [['anchor_id' => 'a', 'content' => 'recorded one', 'order' => 0]],
        ], $account);
        $trailId = $recorded->content[0]['data']['trail_id'];

        // A human edits the trail directly (the SPA authoring path) — now human-owned.
        new TrailStore($this->repository)->editAsHuman($trailId, 'en', 'Human title', [
            ['anchor_id' => 'a', 'content' => 'human one', 'order' => 0],
        ]);

        // The agent re-records over it via the MCP tool.
        $reRecorded = new ReRecordTrailTool($this->entityTypeManager)->execute([
            'trail_id' => $trailId,
            'title' => 'Agent re-record',
            'beacons' => [['anchor_id' => 'a', 'content' => 'agent one', 'order' => 0]],
        ], $account);

        self::assertFalse($reRecorded->isError);
        // Landed as a draft — not promoted — because the live value is human-owned.
        self::assertFalse($reRecorded->content[0]['data']['promoted']);

        // The human's live trail survives unchanged (FR-011 through the tool layer).
        $got = new GetTrailTool($this->entityTypeManager)->execute(['trail_id' => $trailId], $account);
        self::assertSame('Human title', $got->content[0]['data']['title']);
        self::assertSame('human one', $got->content[0]['data']['beacons'][0]['content']);
        self::assertSame('human', $got->content[0]['data']['origin']);
    }

    #[Test]
    public function editing_as_human_via_the_tool_latches_origin_and_survives_rerecord(): void
    {
        // SC-005 end-to-end through the TOOL layer only — no direct TrailStore
        // back-channel. This is the path #1705/CL-8 said had no app surface: the
        // human-owned latch is now reachable via wayfinding_edit_trail.
        $account = $this->account(hasCapability: true);

        $recorded = new RecordTrailTool($this->entityTypeManager)->execute([
            'title' => 'Recorded',
            'beacons' => [['anchor_id' => 'a', 'content' => 'recorded one', 'order' => 0]],
        ], $account);
        $trailId = $recorded->content[0]['data']['trail_id'];

        // A human edits the trail THROUGH THE TOOL — origin latches to human.
        $edited = new EditTrailTool($this->entityTypeManager)->execute([
            'trail_id' => $trailId,
            'title' => 'Human title',
            'beacons' => [['anchor_id' => 'a', 'content' => 'human one', 'order' => 0]],
        ], $account);
        self::assertFalse($edited->isError);
        self::assertSame('human', $edited->content[0]['data']['origin'], 'the edit tool latches origin = human');

        // The agent re-records over it — must land as a draft, never overwrite.
        $reRecorded = new ReRecordTrailTool($this->entityTypeManager)->execute([
            'trail_id' => $trailId,
            'title' => 'Agent re-record',
            'beacons' => [['anchor_id' => 'a', 'content' => 'agent one', 'order' => 0]],
        ], $account);
        self::assertFalse($reRecorded->isError);
        self::assertFalse($reRecorded->content[0]['data']['promoted'], 're-record of a human-owned trail lands a draft');

        // The human's live trail survives byte-for-byte — entirely via tools.
        $got = new GetTrailTool($this->entityTypeManager)->execute(['trail_id' => $trailId], $account);
        self::assertSame('Human title', $got->content[0]['data']['title']);
        self::assertSame('human one', $got->content[0]['data']['beacons'][0]['content']);
        self::assertSame('human', $got->content[0]['data']['origin']);
    }

    #[Test]
    public function edit_is_forbidden_without_the_capability(): void
    {
        $result = new EditTrailTool($this->entityTypeManager)->execute(
            ['trail_id' => '1', 'title' => 'x', 'beacons' => [['anchor_id' => 'a', 'content' => 'b']]],
            $this->account(hasCapability: false),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
    }

    #[Test]
    public function rerecording_an_agent_recorded_trail_promotes_the_live_value(): void
    {
        $account = $this->account(hasCapability: true);

        $recorded = new RecordTrailTool($this->entityTypeManager)->execute([
            'title' => 'v1',
            'beacons' => [['anchor_id' => 'a', 'content' => 'one', 'order' => 0]],
        ], $account);
        $trailId = $recorded->content[0]['data']['trail_id'];

        $reRecorded = new ReRecordTrailTool($this->entityTypeManager)->execute([
            'trail_id' => $trailId,
            'title' => 'v2',
            'beacons' => [['anchor_id' => 'a', 'content' => 'two', 'order' => 0]],
        ], $account);

        self::assertFalse($reRecorded->isError);
        self::assertTrue($reRecorded->content[0]['data']['promoted']);

        $got = new GetTrailTool($this->entityTypeManager)->execute(['trail_id' => $trailId], $account);
        self::assertSame('v2', $got->content[0]['data']['title']);
        self::assertSame('two', $got->content[0]['data']['beacons'][0]['content']);
    }

    #[Test]
    public function get_missing_trail_reports_not_found(): void
    {
        $result = new GetTrailTool($this->entityTypeManager)->execute(
            ['trail_id' => '999', 'langcode' => 'en'],
            $this->account(hasCapability: true),
        );

        self::assertTrue($result->isError);
        self::assertSame('not found', $result->summary);
    }

    private function buildTrailRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(Trail::class, revisionable: true, translatable: true, group: 'content');

        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureTranslationRevisionTable();

        $resolver = new SingleConnectionResolver($db);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        return new EntityRepository(
            $entityType,
            new SqlStorageDriver($resolver, $entityType->getKeys()['id']),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );
    }

    private function account(bool $hasCapability, int $id = 42): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === EmitBeaconController::CAPABILITY,
        );

        return $account;
    }

    /**
     * WP4 (ai-agent M1): cross-account trail-overwrite hole. Wires a REAL
     * {@see EntityAccessHandler} with the REAL {@see TrailAccessPolicy}
     * registered, then attaches it via {@see EditTrailTool::setAccessHandler()}
     * / {@see ReRecordTrailTool::setAccessHandler()} so per-entity enforcement
     * is ON (capability-only mode intentionally no-ops the per-entity gate —
     * see the other tests in this class — so this suite must wire a handler to
     * observe the fix).
     */
    #[Test]
    public function edit_is_forbidden_for_a_non_owner_even_with_the_capability(): void
    {
        $handler = new EntityAccessHandler([new TrailAccessPolicy()]);
        $owner = $this->account(hasCapability: true, id: 100);
        $attacker = $this->account(hasCapability: true, id: 200);

        $recordTool = new RecordTrailTool($this->entityTypeManager);
        $recordTool->setAccessHandler($handler);
        $recorded = $recordTool->execute([
            'title' => 'Owner trail',
            'beacons' => [['anchor_id' => 'a', 'content' => 'owner content', 'order' => 0]],
        ], $owner);
        self::assertFalse($recorded->isError);
        $trailId = $recorded->content[0]['data']['trail_id'];

        $editTool = new EditTrailTool($this->entityTypeManager);
        $editTool->setAccessHandler($handler);
        $result = $editTool->execute([
            'trail_id' => $trailId,
            'title' => 'pwned',
            'langcode' => 'en',
            'beacons' => [['anchor_id' => 'any', 'content' => 'attacker text', 'order' => 0]],
        ], $attacker);

        self::assertTrue($result->isError, 'a non-owner capability holder must be refused, not allowed to overwrite');
        self::assertSame('forbidden', $result->summary);

        // The victim's trail content is unchanged — no write happened.
        $got = new GetTrailTool($this->entityTypeManager)->execute(['trail_id' => $trailId], $owner);
        self::assertSame('Owner trail', $got->content[0]['data']['title']);
        self::assertSame('owner content', $got->content[0]['data']['beacons'][0]['content']);
    }

    #[Test]
    public function rerecord_is_forbidden_for_a_non_owner_even_with_the_capability(): void
    {
        $handler = new EntityAccessHandler([new TrailAccessPolicy()]);
        $owner = $this->account(hasCapability: true, id: 100);
        $attacker = $this->account(hasCapability: true, id: 200);

        $recordTool = new RecordTrailTool($this->entityTypeManager);
        $recordTool->setAccessHandler($handler);
        $recorded = $recordTool->execute([
            'title' => 'Owner trail',
            'beacons' => [['anchor_id' => 'a', 'content' => 'owner content', 'order' => 0]],
        ], $owner);
        self::assertFalse($recorded->isError);
        $trailId = $recorded->content[0]['data']['trail_id'];

        $reRecordTool = new ReRecordTrailTool($this->entityTypeManager);
        $reRecordTool->setAccessHandler($handler);
        $result = $reRecordTool->execute([
            'trail_id' => $trailId,
            'title' => 'pwned',
            'langcode' => 'en',
            'beacons' => [['anchor_id' => 'any', 'content' => 'attacker text', 'order' => 0]],
        ], $attacker);

        self::assertTrue($result->isError, 'a non-owner capability holder must be refused, not allowed to overwrite');
        self::assertSame('forbidden', $result->summary);

        // The victim's trail content is unchanged — no write happened.
        $got = new GetTrailTool($this->entityTypeManager)->execute(['trail_id' => $trailId], $owner);
        self::assertSame('Owner trail', $got->content[0]['data']['title']);
        self::assertSame('owner content', $got->content[0]['data']['beacons'][0]['content']);
    }

    #[Test]
    public function edit_and_rerecord_still_work_for_the_owner_with_a_real_handler_attached(): void
    {
        $handler = new EntityAccessHandler([new TrailAccessPolicy()]);
        $owner = $this->account(hasCapability: true, id: 100);

        $recordTool = new RecordTrailTool($this->entityTypeManager);
        $recordTool->setAccessHandler($handler);
        $recorded = $recordTool->execute([
            'title' => 'Owner trail',
            'beacons' => [['anchor_id' => 'a', 'content' => 'v1', 'order' => 0]],
        ], $owner);
        $trailId = $recorded->content[0]['data']['trail_id'];

        $editTool = new EditTrailTool($this->entityTypeManager);
        $editTool->setAccessHandler($handler);
        $edited = $editTool->execute([
            'trail_id' => $trailId,
            'title' => 'Owner edit',
            'langcode' => 'en',
            'beacons' => [['anchor_id' => 'a', 'content' => 'v2', 'order' => 0]],
        ], $owner);
        self::assertFalse($edited->isError, 'the owner must still be able to edit their own trail');
        self::assertSame('Owner edit', $edited->content[0]['data']['title']);

        $reRecordTool = new ReRecordTrailTool($this->entityTypeManager);
        $reRecordTool->setAccessHandler($handler);
        $reRecorded = $reRecordTool->execute([
            'trail_id' => $trailId,
            'title' => 'Owner re-record',
            'langcode' => 'en',
            'beacons' => [['anchor_id' => 'a', 'content' => 'v3', 'order' => 0]],
        ], $owner);
        self::assertFalse($reRecorded->isError, 'the owner must still be able to re-record their own trail');
    }
}
