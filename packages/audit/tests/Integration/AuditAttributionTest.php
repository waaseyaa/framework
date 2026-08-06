<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Audit\Listener\AgentToolAuditListener;
use Waaseyaa\Audit\Listener\EntityLifecycleAuditListener;
use Waaseyaa\Audit\Listener\McpDispatchAuditListener;
use Waaseyaa\Audit\Listener\PublishPointerAuditListener;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;

/**
 * NFR-002 at 100%: ALL FOUR #1645 mis-attribution surfaces pinned through
 * real dispatch paths (dispatcher → listener → writer → SQLite), asserting
 * `actor_uid` straight off the table.
 *
 *   S1 entity lifecycle — actor = session account, never the entity's `uid` field.
 *   S2 agent tool       — actor from the event's `accountId`.
 *   S3 publish pointer  — first-ever `revision.publish` / `revision.revert` rows.
 *   S4 MCP dispatch     — bearer account carried; null preserved (the
 *                         null-vs-0 distinctness witness, queried at SQL level).
 *
 * Plus the additive migration shape: a pre-mission `audit_event` table gains
 * `actor_uid` + index from `ensureSchema()`, and pre-migration rows read
 * `getActorUid() === null`.
 */
#[CoversNothing]
final class AuditAttributionTest extends TestCase
{
    private DBALDatabase $raw;
    private AuditEventWriter $writer;
    private EventDispatcher $dispatcher;
    private RequestAccountContext $context;

    protected function setUp(): void
    {
        $this->raw = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($this->raw)->ensureSchema();
        $this->writer = new AuditEventWriter(new AppendOnlyAuditDatabase($this->raw));
        $this->dispatcher = new EventDispatcher();
        $this->context = new RequestAccountContext();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOfKind(string $kind): array
    {
        $rows = [];
        $sql = 'SELECT event_kind, actor_uid, account_uid, entity_type_id, attributes FROM audit_event WHERE event_kind = ? ORDER BY id';
        foreach ($this->raw->query($sql, [$kind]) as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    // ------------------------------------------------------------------
    // S1 — entity lifecycle: actor = acting account, NOT the entity's uid
    // ------------------------------------------------------------------

    #[Test]
    public function s1_entity_lifecycle_records_the_session_account_not_the_entity_uid_field(): void
    {
        $this->dispatcher->addSubscriber(
            new EntityLifecycleAuditListener($this->writer, accountContext: $this->context),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            new EntityType(
                id: 'attribution_subject',
                label: 'Attribution subject',
                class: AttributionOwnedEntity::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new InMemoryStorageDriver(),
            $this->dispatcher,
        );

        // The acting session is account 42; the saved entity is OWNED by
        // account 99 (its `uid` field) — the pre-mission listener recorded 99.
        $this->context->set(AuthorizationPrincipalFactory::authenticated(id: 42));

        $entity = new AttributionOwnedEntity(['id' => '1', 'uid' => 99, 'title' => 'Owned by 99']);
        $entity->enforceIsNew(true);
        $repository->save($entity);

        $rows = $this->rowsOfKind('entity.write');
        $this->assertCount(1, $rows);
        $this->assertSame(42, (int) $rows[0]['actor_uid'], 'Actor must be the session account, never the entity uid field');
        $this->assertSame(42, (int) $rows[0]['account_uid']);
    }

    #[Test]
    public function s1_entity_lifecycle_with_no_context_records_a_null_actor(): void
    {
        $this->dispatcher->addSubscriber(
            new EntityLifecycleAuditListener($this->writer, accountContext: $this->context),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            new EntityType(
                id: 'attribution_subject',
                label: 'Attribution subject',
                class: AttributionOwnedEntity::class,
                keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new InMemoryStorageDriver(),
            $this->dispatcher,
        );

        // Context exists but holds nothing — the CLI/bootstrap state. The
        // entity's own uid field (99) must NOT leak in as a fallback.
        $entity = new AttributionOwnedEntity(['id' => '2', 'uid' => 99, 'title' => 'Owned by 99']);
        $entity->enforceIsNew(true);
        $repository->save($entity);

        $rows = $this->rowsOfKind('entity.write');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['actor_uid']);
        $this->assertSame(0, (int) $rows[0]['account_uid']);
    }

    // ------------------------------------------------------------------
    // S2 — agent tool execute: actor from the event's accountId
    // ------------------------------------------------------------------

    #[Test]
    public function s2_agent_tool_call_records_the_event_account_id_as_actor(): void
    {
        $this->dispatcher->addSubscriber(
            new AgentToolAuditListener($this->writer, accountContext: $this->context),
        );

        // Duck-shaped event dispatched under the real FQCN event name — the
        // listener's documented subscription seam (string-FQCN, layer-clean).
        $event = new class {
            public string $runId = 'run-s2';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = 7;
        };
        $this->dispatcher->dispatch($event, AgentToolAuditListener::AGENT_TOOL_CALL_EVENT);

        $rows = $this->rowsOfKind('agent.tool_execute');
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['actor_uid'], 'The hardcoded-0 actor is gone — the initiator account is carried');
        $this->assertSame(7, (int) $rows[0]['account_uid']);
    }

    #[Test]
    public function s2_agent_tool_call_falls_back_to_the_context_then_null(): void
    {
        $this->dispatcher->addSubscriber(
            new AgentToolAuditListener($this->writer, accountContext: $this->context),
        );

        $eventNoAccount = new class {
            public string $runId = 'run-s2b';
            public string $toolName = 'search_web';
            public bool $succeeded = true;
            public ?int $accountId = null;
        };

        // Context fallback.
        $this->context->set(AuthorizationPrincipalFactory::authenticated(id: 12));
        $this->dispatcher->dispatch($eventNoAccount, AgentToolAuditListener::AGENT_TOOL_CALL_EVENT);

        // Neither event nor context → null.
        $this->context->set(null);
        $this->dispatcher->dispatch($eventNoAccount, AgentToolAuditListener::AGENT_TOOL_CALL_EVENT);

        $rows = $this->rowsOfKind('agent.tool_execute');
        $this->assertCount(2, $rows);
        $this->assertSame(12, (int) $rows[0]['actor_uid']);
        $this->assertNull($rows[1]['actor_uid'], 'No initiator and no context must persist as SQL NULL');
        $this->assertSame(0, (int) $rows[1]['account_uid']);
    }

    // ------------------------------------------------------------------
    // S3 — publish pointer: previously invisible operations get audit rows
    // ------------------------------------------------------------------

    #[Test]
    public function s3_publish_and_revert_pointer_moves_record_actor_and_transition(): void
    {
        $this->dispatcher->addSubscriber(
            new PublishPointerAuditListener($this->writer, accountContext: $this->context),
        );

        $this->dispatcher->dispatch(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '12',
            operation: 'publish',
            fromRevisionId: null,
            toRevisionId: 5,
            actorUid: 7,
        ));
        $this->dispatcher->dispatch(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '12',
            operation: 'revert',
            fromRevisionId: 5,
            toRevisionId: 3,
            actorUid: 7,
        ));

        $publishRows = $this->rowsOfKind('revision.publish');
        $this->assertCount(1, $publishRows);
        $this->assertSame(7, (int) $publishRows[0]['actor_uid']);
        $this->assertSame('node', $publishRows[0]['entity_type_id']);
        $publishAttributes = json_decode((string) $publishRows[0]['attributes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            ['entity_id' => '12', 'operation' => 'publish', 'from_revision_id' => null, 'to_revision_id' => 5],
            $publishAttributes,
        );

        $revertRows = $this->rowsOfKind('revision.revert');
        $this->assertCount(1, $revertRows);
        $this->assertSame(7, (int) $revertRows[0]['actor_uid']);
        $revertAttributes = json_decode((string) $revertRows[0]['attributes'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(5, $revertAttributes['from_revision_id']);
        $this->assertSame(3, $revertAttributes['to_revision_id']);
    }

    // ------------------------------------------------------------------
    // S4 — MCP dispatch: bearer account carried; null preserved
    // ------------------------------------------------------------------

    #[Test]
    public function s4_mcp_dispatch_carries_the_bearer_account(): void
    {
        $this->dispatcher->addSubscriber(new McpDispatchAuditListener($this->writer));

        $event = new class {
            public string $method = 'tools/call';
            public array $params = ['tool' => 'search'];
            public ?int $accountUid = 5;
        };
        $this->dispatcher->dispatch($event, McpDispatchAuditListener::EVENT_NAME);

        $rows = $this->rowsOfKind('mcp.dispatch');
        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]['actor_uid']);
        $this->assertSame(5, (int) $rows[0]['account_uid']);
    }

    #[Test]
    public function s4_mcp_dispatch_with_a_null_account_is_the_null_vs_zero_witness(): void
    {
        $this->dispatcher->addSubscriber(new McpDispatchAuditListener($this->writer));

        $event = new class {
            public string $method = 'tools/list';
            public array $params = [];
            public ?int $accountUid = null;
        };
        $this->dispatcher->dispatch($event, McpDispatchAuditListener::EVENT_NAME);

        // The null-vs-0 distinctness invariant asserted at the SQL level in
        // ONE row: actor_uid IS NULL while the legacy account_uid is 0.
        $rows = [];
        $sql = "SELECT actor_uid, account_uid, (actor_uid IS NULL) AS actor_is_null FROM audit_event WHERE event_kind = 'mcp.dispatch'";
        foreach ($this->raw->query($sql) as $row) {
            $rows[] = (array) $row;
        }

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['actor_is_null'], 'actor_uid must be SQL NULL — "no account" is not anonymous 0');
        $this->assertNull($rows[0]['actor_uid']);
        $this->assertSame(0, (int) $rows[0]['account_uid'], 'The legacy column keeps its 0 sentinel in the same row');
    }

    // ------------------------------------------------------------------
    // Migration shape — pre-mission installs gain actor_uid additively
    // ------------------------------------------------------------------

    #[Test]
    public function ensure_schema_adds_actor_uid_and_its_index_to_a_pre_mission_table(): void
    {
        $legacy = DBALDatabase::createSqlite();

        // The audit_event DDL exactly as it shipped before this mission — no
        // actor_uid column, no actor index.
        $legacy->getConnection()->executeStatement(
            'CREATE TABLE audit_event (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                event_kind VARCHAR(64) NOT NULL DEFAULT \'\',
                account_uid INTEGER NOT NULL DEFAULT 0,
                entity_type_id VARCHAR(128) NOT NULL DEFAULT \'\',
                entity_uuid VARCHAR(128) NOT NULL DEFAULT \'\',
                subject_uri VARCHAR(512) NOT NULL DEFAULT \'\',
                outcome VARCHAR(16) NOT NULL DEFAULT \'allowed\',
                severity VARCHAR(16) NOT NULL DEFAULT \'info\',
                attributes TEXT NOT NULL DEFAULT \'{}\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
        );
        // A row written before the migration.
        $legacy->getConnection()->executeStatement(
            "INSERT INTO audit_event (uuid, event_kind, account_uid, subject_uri) VALUES ('legacy-1', 'entity.write', 9, '/entities/note/1')",
        );

        $this->assertFalse($legacy->schema()->fieldExists('audit_event', 'actor_uid'));

        new AuditEventSchemaHandler($legacy)->ensureSchema();

        $this->assertTrue($legacy->schema()->fieldExists('audit_event', 'actor_uid'), 'Guarded ALTER must add actor_uid');

        $indexNames = [];
        foreach ($legacy->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'audit_event'") as $row) {
            $indexNames[] = ((array) $row)['name'];
        }
        $this->assertContains('audit_event_actor_uid', $indexNames, 'The actor index must be created');

        // Idempotency: a second boot is a no-op, not an error.
        new AuditEventSchemaHandler($legacy)->ensureSchema();

        // The pre-migration row reads back a null actor through the read model.
        $events = iterator_to_array(
            new \Waaseyaa\Audit\Query\AuditEventQuery($legacy)->findBy(new \Waaseyaa\Audit\Contract\AuditQuery()),
            false,
        );
        $this->assertCount(1, $events);
        $this->assertNull($events[0]->getActorUid(), 'Rows that predate the column must read actorUid null');
        $this->assertSame(9, $events[0]->getAccountUid(), 'The legacy accessor is untouched');
    }
}

/**
 * Entity OWNED by one account (`uid` field) so a different acting account can
 * prove the actor never comes from the entity (#1645 S1).
 */
final class AttributionOwnedEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = '',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct(
            $values,
            $entityTypeId !== '' ? $entityTypeId : 'attribution_subject',
            $entityKeys !== [] ? $entityKeys : ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            $fieldDefinitions,
        );
    }
}
