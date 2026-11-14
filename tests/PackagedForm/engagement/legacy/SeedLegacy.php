<?php

declare(strict_types=1);

use EngagementConsumer\SyntheticPost;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Engagement\Comment;
use Waaseyaa\Engagement\Reaction;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntitySchemaSyncRunner;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Kernel\EntityTypeManagerFactory;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\User\User;

require __DIR__ . '/vendor/autoload.php';

try {
    $kernel = new HttpKernel(__DIR__);
    $kernel->bootForCli();
    $normalManager = $kernel->getEntityTypeManager();
    $resolver = $kernel->getHttpServiceResolver();
    $layout = getenv('ENGAGEMENT_LAYOUT') ?: 'blob';
    if (!in_array($layout, ['blob', 'column'], true)) {
        throw new RuntimeException('ENGAGEMENT_LAYOUT must be blob or column.');
    }

    if ($layout === 'column') {
        $database = $resolver->resolve(DatabaseInterface::class);
        $logger = $resolver->resolve(LoggerInterface::class);
        $fieldReadScope = $resolver->resolve(AccountFieldReadScopeInterface::class);
        $dispatcher = $kernel->getEventDispatcher();
        if (!$database instanceof DatabaseInterface
            || !$logger instanceof LoggerInterface
            || !$fieldReadScope instanceof AccountFieldReadScopeInterface
            || !$dispatcher instanceof EventDispatcherInterface
            || !$dispatcher instanceof SymfonyEventDispatcherInterface) {
            throw new RuntimeException('Legacy kernel did not expose the canonical schema and repository services.');
        }
        $fieldRegistry = new FieldDefinitionRegistry($kernel->getFieldTypeManager());

        $types = [];
        foreach ($normalManager->getDefinitions() as $definition) {
            $types[$definition->id()] = in_array($definition->id(), ['reaction', 'comment'], true)
                ? legacyColumnType($definition)
                : $definition;
        }
        $entities = new EntityTypeManagerFactory()->build(
            database: $database,
            dispatcher: $dispatcher,
            fieldRegistry: $fieldRegistry,
            logger: $logger,
            accessHandlerResolver: fn(): ?EntityAccessHandler => $resolver->resolve(EntityAccessHandler::class),
            communityScoreResolver: static fn(): null => null,
            accountContextAttacher: static function (object $repository) use ($kernel): void {
                if (method_exists($repository, 'setAccountContext')) {
                    $repository->setAccountContext($kernel->accountContext());
                }
            },
            fieldReadScope: $fieldReadScope,
            fieldTypes: $kernel->getFieldTypeManager(),
        );
        foreach ($types as $type) {
            $entities->registerEntityType($type);
        }
        new EntitySchemaSyncRunner($database, $fieldRegistry, $logger)->run([
            $types['reaction'], $types['comment'],
        ]);
    } else {
        $entities = $normalManager;
    }

    $users = [];
    foreach ([
        ['publisher', 'publisher@example.test'],
        ['actor', 'actor@example.test'],
        ['featured', 'featured@example.test'],
    ] as $index => [$name, $mail]) {
        $user = User::make([
            'name' => $name, 'mail' => $mail, 'status' => 1,
            'roles' => ['authenticated'], 'permissions' => ['access content'], 'created' => 100 + $index,
        ]);
        $entities->getRepository('user')->save($user);
        $users[$name] = (int) $user->id();
    }
    if ($users !== ['publisher' => 1, 'actor' => 2, 'featured' => 3]) {
        throw new RuntimeException('Legacy fixture account IDs did not match canonical repository order.');
    }
    $publisher = $entities->getRepository('user')->find('1');
    if (!$publisher instanceof User) {
        throw new RuntimeException('Legacy publisher account could not be loaded.');
    }
    $kernel->accountContext()->set(new \Waaseyaa\Access\AuthorizationPrincipal(1, true, ['authenticated'], ['access content'], 'legacy-fixture'));

    $roots = [];
    foreach ([
        ['publisher_id' => 1, 'featured_subject_id' => 3, 'title' => 'Legacy root one', 'status' => true],
        ['publisher_id' => 1, 'featured_subject_id' => 3, 'title' => 'Legacy root two', 'status' => true],
    ] as $values) {
        $root = new SyntheticPost($values);
        $entities->getRepository('synthetic_post')->save($root);
        $roots[] = ['type' => 'synthetic_post', 'id' => (int) $root->id(), 'uuid' => $root->uuid()];
    }

    $reactionRepository = $entities->getRepository('reaction');
    $reactions = [];
    foreach ([100, 200] as $created) {
        $reaction = new Reaction([
            'reaction_type' => 'like', 'user_id' => 2, 'target_type' => 'synthetic_post',
            'target_id' => 1, 'created_at' => $created,
        ]);
        $reactionRepository->save($reaction);
        $reactions[] = (int) $reaction->id();
    }
    $nonLike = new Reaction([
        'reaction_type' => 'heart', 'user_id' => 2, 'target_type' => 'synthetic_post',
        'target_id' => 1, 'created_at' => 300,
    ]);
    $reactionRepository->save($nonLike);
    $reactions[] = (int) $nonLike->id();

    $commentIds = [];
    foreach ([
        ['body' => 'legacy top', 'status' => true, 'created_at' => 400],
        ['body' => 'legacy hidden', 'status' => false, 'created_at' => 500],
    ] as $values) {
        $comment = new Comment($values + [
            'user_id' => 2, 'target_type' => 'synthetic_post', 'target_id' => 1,
        ]);
        $entities->getRepository('comment')->save($comment);
        $commentIds[] = (int) $comment->id();
    }
    $invalidTarget = new Comment([
        'body' => 'legacy missing target', 'status' => true, 'created_at' => 600,
        'user_id' => 2, 'target_type' => 'synthetic_post', 'target_id' => 999999,
    ]);
    $entities->getRepository('comment')->save($invalidTarget);
    $commentIds[] = (int) $invalidTarget->id();

    $state = [
        'layout' => $layout,
        'geometry' => 'old-installed-framework-classes; canonical-factory-manager; repository-writes',
        'users' => $users, 'roots' => $roots,
        'reactions' => ['duplicate_like_ids' => array_slice($reactions, 0, 2), 'non_like_id' => $reactions[2]],
        'comments' => ['valid_published_id' => $commentIds[0], 'unpublished_id' => $commentIds[1], 'invalid_target_id' => $commentIds[2]],
        'expected_counts' => ['reactions' => 3, 'comments' => 3],
    ];
    file_put_contents(__DIR__ . '/legacy-state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "legacy engagement records seeded\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'legacy engagement seed failed: ' . $failure::class . ': ' . $failure->getMessage() . "\n");
    exit(1);
}

/** Reviewed legacy-only test helper: old EntityType::fromClass had no backend override. */
function legacyColumnType(\Waaseyaa\Entity\EntityTypeInterface $type): EntityType
{
    return new EntityType(
        id: $type->id(),
        label: $type->getLabel(),
        class: $type->getClass(),
        storageClass: $type->getStorageClass(),
        keys: $type->getKeys(),
        group: $type->getGroup(),
        primaryStorageBackend: 'sql-column',
        api: false,
        _fieldDefinitions: $type->getFieldDefinitions(),
    );
}
