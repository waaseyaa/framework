<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueReadGuardInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Publishing\ContentPublisher;
use Waaseyaa\Publishing\ContentTypeDescriptor;
use Waaseyaa\Publishing\Exception\ContentNotFoundException;
use Waaseyaa\Publishing\Exception\SlugConflictException;
use Waaseyaa\Publishing\FieldSpec;
use Waaseyaa\Publishing\Idempotency\IdempotencyStore;
use Waaseyaa\Publishing\Preview\PreviewLinkService;
use Waaseyaa\Publishing\Tests\Fixtures\PublisherAccount;
use Waaseyaa\Publishing\Tests\Fixtures\SlugScopedViewPolicy;
use Waaseyaa\Publishing\Tests\Fixtures\SpyAuditWriter;
use Waaseyaa\Publishing\Tests\Fixtures\TestArticleEntity;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * Framework #2516 — the six ContentPublisher read operations must consult the
 * entity access policy for the acting principal, not only the coarse publish
 * capability, and must refuse indistinguishably from "not found".
 */
#[CoversClass(ContentPublisher::class)]
final class ContentPublisherReadAccessTest extends TestCase
{
    private const string CAPABILITY = 'publish test articles';

    private DBALDatabase $db;
    private EntityRepository $repo;
    private SpyAuditWriter $audit;
    private PublisherAccount $actor;
    private ?EntityValueReadGuardInterface $priorGuard = null;

    protected function setUp(): void
    {
        $this->priorGuard = EntityReadRuntime::guard();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            new AccountFieldReadScope(),
            static fn(): AccessResult => AccessResult::forbidden('No ambient protected-field grant.'),
        ));

        $db = $this->db = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::publishing($db);
        $entityType = new EntityType(
            id: 'test_article',
            label: 'Test article',
            class: TestArticleEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $schema = new SqlSchemaHandler($entityType, $db);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($db);
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            new EventDispatcher(),
            new RevisionableStorageDriver($resolver, $entityType),
            $db,
        );
        $this->audit = new SpyAuditWriter();
        $this->actor = new PublisherAccount(permissions: [self::CAPABILITY]);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard($this->priorGuard);
    }

    // ------------------------------------------------------------------
    // Collections
    // ------------------------------------------------------------------

    #[Test]
    public function list_omits_entities_the_principal_may_not_view(): void
    {
        $seeder = $this->publisher();
        $visible = $seeder->createDraft($this->actor, $this->values('visible-post', 'Visible'), 'seed-1');
        $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-2');

        $restricted = $this->publisher(['secret-post']);
        $listed = $restricted->list($this->actor);

        self::assertCount(1, $listed);
        self::assertSame($visible['id'], $listed[0]['id']);
        self::assertSame('visible-post', $listed[0]['slug']);
    }

    #[Test]
    public function list_returns_nothing_for_a_principal_holding_the_capability_without_entity_view(): void
    {
        $seeder = $this->publisher();
        $seeder->createDraft($this->actor, $this->values('a-post', 'A'), 'seed-1');
        $seeder->createDraft($this->actor, $this->values('b-post', 'B'), 'seed-2');

        $restricted = $this->publisher(['a-post', 'b-post']);

        self::assertSame([], $restricted->list($this->actor));
        self::assertSame([], $restricted->list($this->actor, publishedOnly: true));
    }

    #[Test]
    public function denied_bundle_hides_every_entity_of_that_bundle(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('bundle-post', 'Bundled'), 'seed-1');

        $restricted = $this->publisher(deniedBundles: ['test_article']);

        self::assertSame([], $restricted->list($this->actor));
        $this->expectException(ContentNotFoundException::class);
        $restricted->get($this->actor, (string) $draft['id']);
    }

    // ------------------------------------------------------------------
    // Single reads
    // ------------------------------------------------------------------

    #[Test]
    public function get_refuses_a_hidden_entity_indistinguishably_from_absence(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');

        $restricted = $this->publisher(['secret-post']);

        $forbidden = $this->capture(fn(): mixed => $restricted->get($this->actor, (string) $draft['id']));
        $absent = $this->capture(fn(): mixed => $restricted->get($this->actor, '999999'));

        self::assertInstanceOf(ContentNotFoundException::class, $forbidden);
        self::assertInstanceOf(ContentNotFoundException::class, $absent);
        self::assertSame('NOT_FOUND', $forbidden->errorCode);
        self::assertSame($absent->errorCode, $forbidden->errorCode);
        self::assertSame(
            \sprintf('No content found for "%s".', (string) $draft['id']),
            $forbidden->getMessage(),
        );
    }

    #[Test]
    public function get_by_slug_refuses_a_hidden_entity(): void
    {
        $seeder = $this->publisher();
        $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');

        $restricted = $this->publisher(['secret-post']);

        $this->expectException(ContentNotFoundException::class);
        $restricted->get($this->actor, 'secret-post');
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    #[Test]
    public function revisions_refuses_a_hidden_entity_before_returning_historical_fields(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');
        $seeder->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'seed-2');

        $restricted = $this->publisher(['secret-post']);

        $this->expectException(ContentNotFoundException::class);
        $restricted->revisions($this->actor, (string) $draft['id']);
    }

    #[Test]
    public function revision_refuses_a_hidden_entity_indistinguishably_from_absence(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');

        $restricted = $this->publisher(['secret-post']);

        $this->expectException(ContentNotFoundException::class);
        $restricted->revision($this->actor, (string) $draft['id'], (int) $draft['revision_id']);
    }

    #[Test]
    public function history_applies_a_decision_per_revision_not_only_per_entity(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('open-post', 'Open'), 'seed-1');
        $updated = $seeder->updateDraft($this->actor, (string) $draft['id'], ['summary' => 'v2'], $draft['revision_id'], 'seed-2');

        // The entity itself stays viewable; only the first revision is hidden.
        $restricted = $this->publisher(deniedRevisionIds: [(int) $draft['revision_id']]);

        $listed = $restricted->revisions($this->actor, (string) $draft['id']);
        $revisionIds = array_map(static fn(array $row): int => (int) $row['revision_id'], $listed);

        self::assertNotContains((int) $draft['revision_id'], $revisionIds);
        self::assertContains((int) $updated['revision_id'], $revisionIds);

        $this->expectException(ContentNotFoundException::class);
        $restricted->revision($this->actor, (string) $draft['id'], (int) $draft['revision_id']);
    }

    // ------------------------------------------------------------------
    // Previews
    // ------------------------------------------------------------------

    #[Test]
    public function preview_refuses_a_hidden_entity_and_issues_no_grant(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');
        $links = new PreviewLinkService('preview-secret', static fn(): int => 1_000_000);

        $restricted = $this->publisher(['secret-post']);

        $thrown = $this->capture(fn(): mixed => $restricted->preview($this->actor, (string) $draft['id'], $links, 600));

        self::assertInstanceOf(ContentNotFoundException::class, $thrown);
        self::assertNotContains('content.preview_issued', $this->audit->kinds());
    }

    #[Test]
    public function preview_revision_refuses_a_hidden_entity_and_issues_no_grant(): void
    {
        $seeder = $this->publisher();
        $draft = $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');
        $links = new PreviewLinkService('preview-secret', static fn(): int => 1_000_000);

        $restricted = $this->publisher(['secret-post']);

        $thrown = $this->capture(fn(): mixed => $restricted->previewRevision(
            $this->actor,
            (string) $draft['id'],
            (int) $draft['revision_id'],
            $links,
            600,
        ));

        self::assertInstanceOf(ContentNotFoundException::class, $thrown);
        self::assertNotContains('content.preview_issued', $this->audit->kinds());
    }

    // ------------------------------------------------------------------
    // Deliberate carve-out
    // ------------------------------------------------------------------

    /**
     * `assertSlugFree()` MUST keep reading through the non-access-checked
     * repository path. It is a uniqueness pre-check, not a read of
     * user-visible content: routing it through the access-checked query would
     * make the conflicting row invisible to an unprivileged caller, who would
     * then be allowed to create a colliding slug. Two rows would then share one
     * slug and the app's slug route would resolve to whichever the storage
     * engine happened to return — a hidden slug collision.
     *
     * This test pins the carve-out. If a future sweep converts
     * `assertSlugFree()` to the access-checked path, this test fails.
     */
    #[Test]
    public function slug_uniqueness_still_sees_entities_the_caller_may_not_view(): void
    {
        $seeder = $this->publisher();
        $seeder->createDraft($this->actor, $this->values('secret-post', 'Secret'), 'seed-1');

        $restricted = $this->publisher(['secret-post']);

        // The caller cannot see the conflicting row at all...
        self::assertSame([], $restricted->list($this->actor));

        // ...but must still be refused the colliding slug.
        $thrown = $this->capture(fn(): mixed => $restricted->createDraft(
            $this->actor,
            $this->values('secret-post', 'Collision attempt'),
            'collide-1',
        ));

        self::assertInstanceOf(SlugConflictException::class, $thrown);
        self::assertSame('SLUG_TAKEN', $thrown->errorCode);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string> $deniedSlugs
     * @param list<int>    $deniedRevisionIds
     * @param list<string> $deniedBundles
     */
    private function publisher(
        array $deniedSlugs = [],
        array $deniedRevisionIds = [],
        array $deniedBundles = [],
    ): ContentPublisher {
        return new ContentPublisher(
            $this->descriptor(),
            $this->repo,
            new IdempotencyStore($this->db),
            $this->audit,
            new EntityAccessHandler([
                new SlugScopedViewPolicy('test_article', $deniedSlugs, $deniedRevisionIds, $deniedBundles),
            ]),
        );
    }

    private function descriptor(): ContentTypeDescriptor
    {
        return new ContentTypeDescriptor(
            entityTypeId: 'test_article',
            bundle: null,
            slugField: 'slug',
            statusField: 'status',
            writableFields: [
                'slug' => new FieldSpec(type: 'string', required: true, maxLength: 100),
                'title' => new FieldSpec(type: 'string', required: true, maxLength: 200),
                'summary' => new FieldSpec(type: 'text'),
            ],
            htmlSanitizer: null,
            validators: [],
            publishCapability: self::CAPABILITY,
        );
    }

    /** @return array<string, mixed> */
    private function values(string $slug, string $title): array
    {
        return ['slug' => $slug, 'title' => $title, 'summary' => 'A summary.'];
    }

    private function capture(callable $operation): ?\Throwable
    {
        try {
            $operation();
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
