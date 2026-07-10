<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * CW-v1 WP-2 rework Task 2 (#1920, final-review finding #7): an alpha.256
 * install persisted the `editorial` workflow config entity BEFORE
 * `restore_to_published` (and any future shipped entry) existed —
 * {@see WorkflowServiceProvider::seedDefaultEditorialWorkflow()} used to
 * early-return the instant `editorial` was found, so those installs never
 * gained entries shipped after their first boot. Archived content on those
 * installs was a dead end (finding #7's "unrecoverable" bug).
 *
 * This proves the real `boot()`-driven upgrade path end to end: an exact
 * alpha.256 5-transition fixture is persisted BEFORE `boot()` runs (not
 * constructed in memory and inspected), a real `WorkflowServiceProvider::boot()`
 * performs the additive top-up, and every assertion RELOADS from the
 * repository rather than trusting the in-memory reference — proving the
 * merge was actually saved as an UPDATE of the same `editorial` id, not
 * merely mutated in memory.
 */
#[CoversNothing]
final class DefaultWorkflowSeedTopUpTest extends TestCase
{
    /**
     * The exact persisted shape of released alpha.256 installs: identical
     * states to the current shipped `EDITORIAL`, but only the five
     * transitions that predate `restore_to_published`.
     *
     * @var array<string, mixed>
     */
    private const array STALE_EDITORIAL_ALPHA_256 = [
        'id' => 'editorial',
        'label' => 'Editorial',
        'initial_state' => 'draft',
        'states' => [
            'draft' => ['label' => 'Draft', 'published' => false, 'default_revision' => false],
            'review' => ['label' => 'In review', 'published' => false, 'default_revision' => false],
            'published' => ['label' => 'Published', 'published' => true, 'default_revision' => true],
            'archived' => ['label' => 'Archived', 'published' => false, 'default_revision' => true],
        ],
        'transitions' => [
            'submit_for_review' => [
                'label' => 'Submit for review',
                'from' => ['draft'],
                'to' => 'review',
                'permission' => 'use editorial transition submit_for_review',
            ],
            'publish' => [
                'label' => 'Publish',
                'from' => ['draft', 'review'],
                'to' => 'published',
                'permission' => 'use editorial transition publish',
            ],
            'reject' => [
                'label' => 'Send back',
                'from' => ['review'],
                'to' => 'draft',
                'permission' => 'use editorial transition reject',
            ],
            'archive' => [
                'label' => 'Archive',
                'from' => ['published'],
                'to' => 'archived',
                'permission' => 'use editorial transition archive',
            ],
            'restore' => [
                'label' => 'Restore to draft',
                'from' => ['archived'],
                'to' => 'draft',
                'permission' => 'use editorial transition restore',
            ],
        ],
    ];

    #[Test]
    public function boot_additively_tops_up_a_stale_alpha_256_editorial_with_restore_to_published(): void
    {
        [$entityTypeManager, $kernelServices, $logger] = $this->buildHarness();
        $repository = $entityTypeManager->getRepository('workflow');

        $stale = new Workflow(self::STALE_EDITORIAL_ALPHA_256);
        $stale->enforceIsNew();
        $repository->save($stale);

        $this->bootProvider($kernelServices);

        $reloaded = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $reloaded);
        $this->assertTrue(
            $reloaded->hasTransition('restore_to_published'),
            'Upgrade top-up must add the missing restore_to_published transition to a stale persisted editorial workflow.',
        );

        $added = $reloaded->getTransition('restore_to_published');
        $this->assertNotNull($added);
        $this->assertSame(['archived'], $added->from);
        $this->assertSame('published', $added->to);
        $this->assertSame('use editorial transition restore_to_published', $added->permission);

        // The five pre-existing transitions must be untouched by machine name.
        foreach (['submit_for_review', 'publish', 'reject', 'archive', 'restore'] as $id) {
            $this->assertTrue($reloaded->hasTransition($id), "Pre-existing transition '$id' must survive the top-up.");
        }
        $this->assertCount(6, $reloaded->getTransitions());

        // All four states were already present — no state should be added.
        $this->assertCount(4, $reloaded->getStates());

        $this->assertNotEmpty($logger->infos);
        $topUpLog = $logger->infos[0];
        $this->assertSame('workflows.default_seed_topup', $topUpLog['message']);
        $this->assertSame(['restore_to_published'], $topUpLog['context']['added_transitions'] ?? null);
        $this->assertSame([], $topUpLog['context']['added_states'] ?? null);
    }

    #[Test]
    public function boot_top_up_preserves_a_customized_permission_string_on_an_existing_transition(): void
    {
        [$entityTypeManager, $kernelServices] = $this->buildHarness();
        $repository = $entityTypeManager->getRepository('workflow');

        $customized = self::STALE_EDITORIAL_ALPHA_256;
        $customized['transitions']['publish']['permission'] = 'use editorial transition publish custom';

        $stale = new Workflow($customized);
        $stale->enforceIsNew();
        $repository->save($stale);

        $this->bootProvider($kernelServices);

        $reloaded = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $reloaded);

        $publish = $reloaded->getTransition('publish');
        $this->assertNotNull($publish);
        $this->assertSame(
            'use editorial transition publish custom',
            $publish->permission,
            'An operator-customized permission string on a pre-existing transition must survive the top-up untouched.',
        );

        $this->assertTrue($reloaded->hasTransition('restore_to_published'));
    }

    #[Test]
    public function second_boot_against_an_already_topped_up_workflow_is_a_steady_state_no_op(): void
    {
        [$entityTypeManager, $kernelServices, $logger] = $this->buildHarness();
        $repository = $entityTypeManager->getRepository('workflow');

        $stale = new Workflow(self::STALE_EDITORIAL_ALPHA_256);
        $stale->enforceIsNew();
        $repository->save($stale);

        // First boot performs the top-up.
        $this->bootProvider($kernelServices);
        $this->assertCount(1, $logger->infos, 'First boot against a stale fixture must top up exactly once.');

        $afterFirstBoot = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $afterFirstBoot);
        $this->assertCount(6, $afterFirstBoot->getTransitions());

        // Second boot (fresh provider instance, same repository/backing store):
        // nothing is missing any more, so the seed must write nothing and log
        // nothing further — steady state is read + compare only.
        $this->bootProvider($kernelServices);
        $this->assertCount(
            1,
            $logger->infos,
            'A second boot against an already-topped-up workflow must not log another top-up (no save performed).',
        );

        $afterSecondBoot = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $afterSecondBoot);
        $this->assertCount(6, $afterSecondBoot->getTransitions());
    }

    #[Test]
    public function an_invalid_merged_shape_is_logged_and_skipped_leaving_the_persisted_entity_unchanged(): void
    {
        [$entityTypeManager, $kernelServices, $logger] = $this->buildHarness();
        $repository = $entityTypeManager->getRepository('workflow');

        // A corrupted persisted entity: an operator-added custom transition
        // ('custom_broken', NOT one of the shipped machine names) references
        // a state that does not exist. This is already invalid before any
        // top-up is attempted, and it is untouched by the merge (top-up only
        // ever ADDS shipped entries that are missing by machine name — it
        // never inspects or repairs unrelated existing entries). Merging in
        // the missing 'restore_to_published' transition does not fix the
        // pre-existing violation, so the top-up's own WorkflowValidator pass
        // over the MERGED result must catch it, log, and skip — never
        // boot-crash, and never silently persist a still-broken shape.
        $corrupted = self::STALE_EDITORIAL_ALPHA_256;
        $corrupted['transitions']['custom_broken'] = [
            'label' => 'Custom broken',
            'from' => ['draft'],
            'to' => 'nonexistent_state',
            'permission' => 'use editorial transition custom_broken',
        ];

        $stale = new Workflow($corrupted);
        $stale->enforceIsNew();
        $repository->save($stale);

        $this->bootProvider($kernelServices);

        $reloaded = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $reloaded);
        $this->assertFalse(
            $reloaded->hasTransition('restore_to_published'),
            'An invalid merged shape must be skipped entirely — the persisted entity must be left unchanged.',
        );
        $this->assertTrue(
            $reloaded->hasTransition('custom_broken'),
            'The persisted entity must be left exactly as it was — including its pre-existing corruption.',
        );
        $this->assertCount(6, $reloaded->getTransitions());

        $this->assertNotEmpty($logger->warnings);
        $this->assertSame('workflows.default_seed_topup_invalid', $logger->warnings[0]['message']);
        $this->assertNotEmpty($logger->warnings[0]['context']['violations'] ?? []);
    }

    #[Test]
    public function a_fresh_install_with_no_persisted_editorial_still_seeds_the_full_descoped_shape(): void
    {
        [$entityTypeManager, $kernelServices] = $this->buildHarness();
        $repository = $entityTypeManager->getRepository('workflow');

        $this->assertNull($repository->find('editorial'));

        $this->bootProvider($kernelServices);

        $seeded = $repository->find('editorial');
        $this->assertInstanceOf(Workflow::class, $seeded);
        $this->assertCount(6, $seeded->getTransitions());
        $this->assertTrue($seeded->hasTransition('restore_to_published'));
    }

    /**
     * @return array{0: EntityTypeManager, 1: KernelServicesInterface, 2: TopUpSpyLogger}
     */
    private function buildHarness(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configFactory = new ConfigFactory(new MemoryStorage(), $dispatcher);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();

            return new EntityRepository(
                $definition,
                new SqlStorageDriver(new SingleConnectionResolver($db)),
                $dispatcher,
                null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workflow',
            label: 'Workflow',
            class: Workflow::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'workflows',
        ));

        $logger = new TopUpSpyLogger();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $logger) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly LoggerInterface $logger,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    LoggerInterface::class => $this->logger,
                    default => null,
                };
            }
        };

        return [$entityTypeManager, $kernelServices, $logger];
    }

    private function bootProvider(KernelServicesInterface $kernelServices): void
    {
        $provider = new WorkflowServiceProvider();
        $provider->setKernelServices($kernelServices);
        $provider->register();
        $provider->boot();
    }
}

final class TopUpSpyLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $infos = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void {}

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = ['message' => (string) $message, 'context' => $context];
    }

    public function notice(string|\Stringable $message, array $context = []): void {}

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->infos[] = ['message' => (string) $message, 'context' => $context];
    }

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void {}
}
