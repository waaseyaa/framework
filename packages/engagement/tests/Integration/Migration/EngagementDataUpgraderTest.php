<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Tests\Integration\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\EngagementAccess;
use Waaseyaa\Engagement\EngagementMutationInvariant;
use Waaseyaa\Engagement\EngagementMutationScope;
use Waaseyaa\Engagement\EngagementPolicyInterface;
use Waaseyaa\Engagement\EngagementServiceProvider;
use Waaseyaa\Engagement\Migration\EngagementDataUpgrader;
use Waaseyaa\Engagement\Reaction;
use Waaseyaa\EntityStorage\EntitySchemaSync;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Log\NullLogger;

final class EngagementDataUpgraderTest extends TestCase
{
    public static function layouts(): iterable
    {
        yield 'sql-blob' => [false];
        yield 'sql-column' => [true];
    }

    #[Test]
    #[DataProvider('layouts')]
    public function migrates_legacy_rows_deduplicates_quarantines_and_is_idempotent(bool $columnLayout): void
    {
        [$database, $manager, $scope, $root, $actor] = $this->environment($columnLayout);
        $this->seedLegacyRows($database, $manager, $scope, $root, $actor);

        $upgrader = new EngagementDataUpgrader($database, $manager, $scope);
        $first = $upgrader->runBatch(200);
        self::assertTrue($first['done']);
        self::assertSame(2, $first['migrated']);
        self::assertSame(3, $first['quarantined']);
        self::assertSame(1, $first['duplicate_likes_removed']);
        self::assertTrue($upgrader->isReady());

        $commentRepository = $manager->getRepository('comment');
        $comments = $commentRepository->findBy([]);
        $commentFields = $scope->runMigration(static function () use ($comments, $root, $commentRepository): array {
            $byBody = [];
            foreach ($comments as $comment) {
                $byBody[(string) $comment->get('body')] = $comment;
            }
            $unpublished = $byBody['legacy hidden'] ?? null;
            if ($unpublished === null) { throw new \RuntimeException('Migrated hidden legacy comment was not found.'); }
            return [
                'active_root' => $byBody['legacy active']->get('root_uuid'),
                'active_lifecycle' => $byBody['legacy active']->get('lifecycle'),
                'unpublished_body' => $unpublished->get('body'),
                'unpublished_status' => (bool) $unpublished->get('status'),
                'unpublished_lifecycle' => $unpublished->get('lifecycle'),
                'unpublished_removed_at' => $unpublished->get('removed_at'),
            ];
        });
        self::assertSame($root->uuid(), $commentFields['active_root']);
        self::assertSame('active', $commentFields['active_lifecycle']);
        self::assertSame('legacy hidden', $commentFields['unpublished_body']);
        self::assertFalse($commentFields['unpublished_status']);
        self::assertSame('unpublished', $commentFields['unpublished_lifecycle']);
        self::assertNull($commentFields['unpublished_removed_at']);
        self::assertSame(1, (int) $database->getConnection()->fetchOne("SELECT COUNT(*) FROM engagement_comment_index WHERE lifecycle = 'unpublished'"));
        self::assertSame(1, (int) $database->getConnection()->fetchOne("SELECT COUNT(*) FROM engagement_comment_index WHERE lifecycle = 'active'"));

        $commentLifecycle = $scope->runMigration(static function () use ($comments): array {
            return array_map(static fn ($comment): array => [
                'body' => $comment->get('body'),
                'root_uuid' => $comment->get('root_uuid'),
                'lifecycle' => $comment->get('lifecycle'),
            ], $comments);
        });
        self::assertSame(3, count(array_filter($commentLifecycle, static fn (array $row): bool => $row['lifecycle'] === 'quarantined')));
        foreach ($commentLifecycle as $row) {
            if ($row['lifecycle'] === 'quarantined') {
                self::assertSame('', $row['root_uuid']);
            }
        }

        $connection = $database->getConnection();
        $identity = $connection->fetchAssociative('SELECT * FROM engagement_like_identity');
        $reactions = $manager->getRepository('reaction');
        $earliest = $reactions->findBy(['uuid' => $identity['reaction_uuid']], null, 1)[0];
        $reactionFields = $scope->runMigration(static function () use ($earliest, $reactions): array {
            $nonLike = $reactions->findBy(['reaction_type' => 'love'], null, 1)[0];
            return [
                'earliest_created' => $earliest->get('created_at'),
                'earliest_root' => $earliest->get('root_uuid'),
                'nonlike_root' => $nonLike->get('root_uuid'),
                'nonlike_lifecycle' => $nonLike->get('lifecycle'),
            ];
        });
        self::assertSame(100, (int) $reactionFields['earliest_created']);
        self::assertSame($root->uuid(), $reactionFields['earliest_root']);
        self::assertSame('old', $reactionFields['nonlike_root']);
        self::assertSame('active', $reactionFields['nonlike_lifecycle']);
        self::assertSame(4, (int) $connection->fetchOne("SELECT COUNT(*) FROM reaction WHERE reaction_type = 'love'"));
        $loveReactions = $reactions->findBy(['reaction_type' => 'love']);
        $reactionLifecycle = $scope->runMigration(static fn (): array => array_map(static fn ($reaction): array => [
            'user_id' => (int) $reaction->get('user_id'),
            'target_type' => $reaction->get('target_type'),
            'target_id' => (int) $reaction->get('target_id'),
            'root_uuid' => $reaction->get('root_uuid'),
            'lifecycle' => $reaction->get('lifecycle'),
        ], $loveReactions));
        $sort = static function (array &$rows): void { usort($rows, static fn (array $left, array $right): int => $left <=> $right); };
        $expectedNonlikes = [
            ['user_id' => (int) $actor->id(), 'target_type' => 'article', 'target_id' => (int) $root->id(), 'root_uuid' => 'old', 'lifecycle' => 'active'],
            ['user_id' => (int) $actor->id(), 'target_type' => 'missing_root_type', 'target_id' => 1, 'root_uuid' => '', 'lifecycle' => 'quarantined'],
            ['user_id' => 0, 'target_type' => 'article', 'target_id' => (int) $root->id(), 'root_uuid' => '', 'lifecycle' => 'quarantined'],
            ['user_id' => (int) $actor->id(), 'target_type' => 'comment', 'target_id' => 1, 'root_uuid' => 'old', 'lifecycle' => 'active'],
        ];
        $sort($reactionLifecycle);
        $sort($expectedNonlikes);
        self::assertSame($expectedNonlikes, $reactionLifecycle, 'Every valid and invalid non-like legacy row must retain its root, actor, and lifecycle integrity state.');

        $second = $upgrader->runBatch(200);
        self::assertTrue($second['done']);
        self::assertSame(0, $second['scanned']);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM engagement_like_identity'));
    }

    #[Test]
    public function an_explicit_run_marks_an_empty_installation_ready(): void
    {
        [$database, $manager, $scope] = $this->environment(false);
        $upgrader = new EngagementDataUpgrader($database, $manager, $scope);

        self::assertFalse($upgrader->isReady());
        $result = $upgrader->runBatch();

        self::assertTrue($result['done']);
        self::assertSame(0, $result['scanned']);
        self::assertTrue($upgrader->isReady());
        self::assertSame(2, (int) $database->getConnection()->fetchOne('SELECT COUNT(*) FROM engagement_upgrade_progress WHERE complete = 1'));
    }

    #[Test]
    #[DataProvider('layouts')]
    public function a_failed_batch_rolls_back_entity_changes_and_progress(bool $columnLayout): void
    {
        [$database, $manager, $scope, $root, $actor] = $this->environment($columnLayout);
        $comment = $this->legacyComment($root, $actor, 'rollback body', rootUuid: 'stale-root-uuid');
        $this->saveInMigrationScope($database, $scope, $manager->getRepository('comment'), $comment);
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TRIGGER engagement_test_abort_index BEFORE INSERT ON engagement_comment_index
            BEGIN SELECT RAISE(ABORT, 'intentional test rollback'); END
            SQL);

        $upgrader = new EngagementDataUpgrader($database, $manager, $scope);
        try {
            $upgrader->runBatch(200);
            self::fail('Expected the injected index failure.');
        } catch (\Throwable $error) {
            self::assertStringContainsString('intentional test rollback', $error->getMessage());
        } finally {
            $database->getConnection()->executeStatement('DROP TRIGGER IF EXISTS engagement_test_abort_index');
        }

        $loaded = $manager->getRepository('comment')->find($comment->id());
        self::assertSame('stale-root-uuid', $scope->runMigration(static fn () => $loaded->get('root_uuid')));
        self::assertSame(0, (int) $database->getConnection()->fetchOne("SELECT last_id FROM engagement_upgrade_progress WHERE id = 'comment'"));
        self::assertSame(0, (int) $database->getConnection()->fetchOne("SELECT complete FROM engagement_upgrade_progress WHERE id = 'comment'"));
    }

    /** @return array{DBALDatabase,EntityTypeManager,EngagementMutationScope,?ContentEntityBase,?ContentEntityBase} */
    private function environment(bool $columnLayout): array
    {
        $database = DBALDatabase::createSqlite();
        $provider = new EngagementServiceProvider();
        $provider->register();
        $definitions = [];
        foreach ($provider->getEntityTypes() as $type) {
            if ($columnLayout && in_array($type->id(), ['comment', 'reaction'], true)) {
                $type = new EntityType(
                    id: $type->id(), label: $type->getLabel(), class: $type->getClass(),
                    storageClass: $type->getStorageClass(), keys: $type->getKeys(),
                    revisionable: $type->isRevisionable(), revisionDefault: $type->getRevisionDefault(),
                    translatable: $type->isTranslatable(), bundleEntityType: $type->getBundleEntityType(),
                    constraints: $type->getConstraints(), group: $type->getGroup(), description: $type->getDescription(),
                    primaryStorageBackend: 'sql-column', tenancy: $type->getTenancy(),
                    discoverable: $type->isDiscoverable(), api: $type->isApiExposed(),
                    _fieldDefinitions: $type->getFieldDefinitions(),
                    _foreignKeys: $type->getStorageForeignKeys(),
                    _storageUniqueKeys: $type->getStorageUniqueKeys(),
                    _storageSchemaTransitions: $type->getStorageSchemaTransitions(),
                    requiredMutationInvariant: $type->getRequiredMutationInvariant(),
                );
            }
            $definitions[] = $type;
        }
        $definitions[] = EntityType::fromClass(UpgradeTestActor::class);
        $definitions[] = EntityType::fromClass(UpgradeTestRoot::class);

        $accountContext = new RequestAccountContext();
        $fieldReadScope = new AccountFieldReadScope();
        $scope = new EngagementMutationScope($accountContext, $fieldReadScope);
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $fieldReadScope,
            static fn (...$arguments) => AccessResult::allowed('test migration read'),
        ));
        $registry = new FieldDefinitionRegistry();
        $manager = null;
        $manager = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: new SymfonyEventDispatcherAdapter(),
            fieldRegistry: $registry,
            logger: new NullLogger(),
            accessHandlerResolver: static fn () => null,
            communityScoreResolver: static fn () => null,
            accountContextAttacher: static function (object $repository) use ($accountContext): void {
                $repository->setAccountContext($accountContext);
            },
            fieldReadScope: $fieldReadScope,
            fieldTypes: $registry->fieldTypeManager(),
            mutationInvariantResolver: static function (string $id) use ($database, &$manager, $scope): object {
                if ($id !== EngagementMutationInvariant::class || !$manager instanceof EntityTypeManagerInterface) {
                    throw new \LogicException('Unexpected invariant resolution.');
                }
                return new EngagementMutationInvariant(
                    $database,
                    new EngagementAccess($database, $manager, new EntityAccessHandler(), new UpgradeTestPolicy($database)),
                    $scope,
                );
            },
        );
        foreach ($definitions as $definition) {
            $manager->registerEntityType($definition);
        }

        (new EntitySchemaSync($database, $registry, fieldTypes: $registry->fieldTypeManager()))
            ->syncAll($manager->getDefinitions());
        EntityMutationAuthoritySchema::ensure($database);
        $migration = require dirname(__DIR__, 3) . '/migrations/2026_11_13_000001_engagement_auxiliary_schema.php';
        $migration->up(new \Waaseyaa\Foundation\Migration\SchemaBuilder($database->getConnection()));

        $actor = $manager->getRepository('user')->create(['name' => 'Legacy author']);
        $manager->getRepository('user')->save($actor);
        $root = $manager->getRepository('article')->create();
        $manager->getRepository('article')->save($root);
        return [$database, $manager, $scope, $root, $actor];
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
        parent::tearDown();
    }

    private function seedLegacyRows(DBALDatabase $database, EntityTypeManager $manager, EngagementMutationScope $scope, ContentEntityBase $root, ContentEntityBase $actor): void
    {
        $commentRepository = $manager->getRepository('comment');
        $comments = [
            $this->legacyComment($root, $actor, 'legacy active', rootUuid: 'stale-root-uuid'),
            $this->legacyComment($root, $actor, 'legacy hidden', rootUuid: 'stale-root-uuid', status: false),
            $this->legacyComment($root, $actor, 'unknown root', targetId: 987654, rootUuid: '', lifecycle: 'quarantined'),
            $this->legacyComment($root, $actor, 'invalid author', userId: 0, rootUuid: '', lifecycle: 'quarantined'),
            $this->legacyComment($root, $actor, 'engagement cycle', targetType: 'comment', rootUuid: 'stale-root-uuid'),
        ];
        foreach ($comments as $comment) {
            $this->saveInMigrationScope($database, $scope, $commentRepository, $comment);
        }

        $reactionRepository = $manager->getRepository('reaction');
        $reactions = [
            new Reaction(['user_id' => $actor->id(), 'target_type' => 'article', 'target_id' => $root->id(), 'reaction_type' => 'like', 'root_uuid' => 'old', 'created_at' => 200]),
            new Reaction(['user_id' => $actor->id(), 'target_type' => 'article', 'target_id' => $root->id(), 'reaction_type' => 'like', 'root_uuid' => 'old', 'created_at' => 100]),
            new Reaction(['user_id' => $actor->id(), 'target_type' => 'article', 'target_id' => $root->id(), 'reaction_type' => 'love', 'root_uuid' => 'old', 'created_at' => 300]),
            new Reaction(['user_id' => $actor->id(), 'target_type' => 'missing_root_type', 'target_id' => 1, 'reaction_type' => 'love', 'root_uuid' => '', 'lifecycle' => 'quarantined']),
            new Reaction(['user_id' => 0, 'target_type' => 'article', 'target_id' => $root->id(), 'reaction_type' => 'love', 'root_uuid' => '', 'lifecycle' => 'quarantined']),
            new Reaction(['user_id' => $actor->id(), 'target_type' => 'comment', 'target_id' => 1, 'reaction_type' => 'love', 'root_uuid' => 'old']),
        ];
        foreach ($reactions as $reaction) {
            $this->saveInMigrationScope($database, $scope, $reactionRepository, $reaction);
        }
    }

    private function legacyComment(ContentEntityBase $root, ContentEntityBase $actor, string $body, ?int $targetId = null, string $targetType = 'article', string $rootUuid = '', int $userId = -1, bool $status = true, string $lifecycle = 'active'): Comment
    {
        return new Comment([
            'user_id' => $userId < 0 ? $actor->id() : $userId,
            'target_type' => $targetType,
            'target_id' => $targetId ?? $root->id(),
            'body' => $body,
            'root_uuid' => $rootUuid,
            'lifecycle' => $lifecycle,
            'status' => $status,
            'created_at' => 100,
        ]);
    }

    private function saveInMigrationScope(DBALDatabase $database, EngagementMutationScope $scope, object $repository, object $entity): void
    {
        $database->transactional(static fn () => $scope->runMigration(static fn () => $repository->save($entity, false)));
    }
}

#[ContentEntityType(id: 'user', label: 'Upgrade test actor', api: false)]
#[ContentEntityKeys(id: 'uid', uuid: 'uuid', label: 'name')]
final class UpgradeTestActor extends ContentEntityBase
{
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [], array $fieldDefinitions = [])
    {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

#[ContentEntityType(id: 'article', label: 'Upgrade test root', api: false)]
#[ContentEntityKeys(id: 'aid', uuid: 'uuid', label: 'title')]
final class UpgradeTestRoot extends ContentEntityBase
{
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [], array $fieldDefinitions = [])
    {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

final readonly class UpgradeTestPolicy implements EngagementPolicyInterface
{
    public function __construct(private DBALDatabase $database) {}
    public function databaseIdentity(): string { return $this->database->databaseIdentity(); }
    public function generation(AuthorizationPrincipalInterface $viewer): string { return '1'; }
    public function canViewRoot(AuthorizationPrincipalInterface $viewer, \Waaseyaa\Entity\EntityInterface $root): bool { return true; }
    public function isEligible(AuthorizationPrincipalInterface $actor): bool { return true; }
    public function canInteract(AuthorizationPrincipalInterface $actor, \Waaseyaa\Entity\EntityInterface $root, string $operation): bool { return true; }
    public function canViewActor(AuthorizationPrincipalInterface $viewer, int $authorId, \Waaseyaa\Entity\EntityInterface $root): bool { return true; }
    public function canModerate(AuthorizationPrincipalInterface $actor, \Waaseyaa\Entity\EntityInterface $root): bool { return true; }
}
