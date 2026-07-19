<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Node\Node;
use Waaseyaa\Node\NodeAccessPolicy;
use Waaseyaa\Node\NodeServiceProvider;
use Waaseyaa\Node\NodeType;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\Workflows\DefaultWorkflows;
use Waaseyaa\Workflows\Transition\TransitionService;
use Waaseyaa\Workflows\Workflow;
use Waaseyaa\Workflows\WorkflowServiceProvider;

/**
 * CW-v1 option-1 (#1920 PR-3, design §4 item 4): SSR preview renders the
 * WORKING COPY only when `previewRequested` AND the requester is GENUINELY
 * authorized to preview a draft — never merely because the entity itself
 * reports 'published' (which, under default-revision discipline, the
 * find()-loaded entity always does while a forward draft is in flight).
 * Boot pattern mirrors {@see \Waaseyaa\Api\Tests\Integration\WorkingCopyPointerAwarenessFlowTest}
 * (real Node + Workflow wiring, test-local `editorial_forward` workflow
 * carrying the `revise` edge).
 */
#[CoversNothing]
final class SsrPreviewWorkingCopyTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrServiceProvider::setTwigEnvironment(null);
    }

    #[Test]
    public function anonymous_render_is_byte_stable_mid_draft_even_with_preview_requested(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, ['administer nodes', 'use editorial_forward transition publish', 'use editorial_forward transition revise']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);

        // Anonymous, non-preview capture — the pre-draft baseline.
        $handler = $this->handler($entityTypeManager);
        $path = '/node/' . $entityId;
        $baseline = $handler->handleRenderPage($path, new AnonymousUser(), HttpRequest::create($path));
        self::assertSame(200, $baseline['status']);
        self::assertStringContainsString('Original title', (string) $baseline['content']);

        // Forward-draft edit (published -> draft).
        $tip = $nodeRepository->find($entityId);
        self::assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Draft title');
        $transitionService->transition($tip, 'revise', $editor);

        // Anonymous, NO preview requested: byte-stable (unchanged assertion,
        // the pre-existing non-preview path).
        $anonNoPreview = $handler->handleRenderPage($path, new AnonymousUser(), HttpRequest::create($path));
        self::assertSame($baseline['content'], $anonNoPreview['content'], 'The public (non-preview) path must be byte-untouched by PR-3.');

        // Anonymous WITH ?preview=1: must NOT see the draft — canRender()
        // against the working copy denies (anonymous, no preview permission),
        // so the swap never happens and served content is unchanged.
        $previewPath = $path . '?preview=1';
        $anonWithPreview = $handler->handleRenderPage($path, new AnonymousUser(), HttpRequest::create($previewPath));
        self::assertStringContainsString('Original title', (string) $anonWithPreview['content'], 'Anonymous ?preview=1 must still serve PUBLISHED content, never the draft.');
        self::assertStringNotContainsString('Draft title', (string) $anonWithPreview['content']);
    }

    #[Test]
    public function an_authorized_preview_request_renders_the_working_copy(): void
    {
        [$entityTypeManager, , $transitionService, $accountContext] = $this->bootWiredProviders();
        $nodeRepository = $entityTypeManager->getRepository('node');

        $editor = $this->account(11, ['administer nodes', 'use editorial_forward transition publish', 'use editorial_forward transition revise']);
        $accountContext->set($editor);

        $node = new Node(['title' => 'Original title', 'type' => 'article', 'slug' => 'original-title']);
        $node->enforceIsNew();
        $nodeRepository->save($node);
        $entityId = (string) $node->id();
        $transitionService->transition($nodeRepository->find($entityId), 'publish', $editor);

        $tip = $nodeRepository->find($entityId);
        self::assertNotNull($tip);
        \assert($tip instanceof Node);
        $tip->setTitle('Draft title');
        $transitionService->transition($tip, 'revise', $editor);

        $handler = $this->handler($entityTypeManager);
        $path = '/node/' . $entityId;
        $previewPath = $path . '?preview=1';

        // The editor holds 'administer nodes' (EditorialVisibilityResolver's
        // preview-branch admin bypass) — a genuinely authorized preview.
        $result = $handler->handleRenderPage($path, $editor, HttpRequest::create($previewPath));

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('Draft title', (string) $result['content'], 'An authorized preview request must render the WORKING COPY.');
    }

    private function handler(EntityTypeManagerInterface $entityTypeManager): SsrPageHandler
    {
        $db = DBALDatabase::createSqlite();
        SsrServiceProvider::setTwigEnvironment(new Environment(new ArrayLoader([
            'entity.html.twig' => '<article><h1>{{ entity.label }}</h1></article>',
        ])));

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($entityTypeManager, $db),
            projectRoot: '/tmp/test-project',
            config: [],
            accessHandler: new EntityAccessHandler([
                new NodeAccessPolicy(),
                new PublishedContentAccessPolicy($entityTypeManager),
            ]),
        );
    }

    /**
     * @param list<string> $permissions
     */
    private function account(int $id, array $permissions): AccountInterface
    {
        return new class ($id, $permissions) implements AccountInterface {
            public function __construct(private readonly int $accountId, private readonly array $permissions) {}
            public function id(): int|string { return $this->accountId; }
            public function hasPermission(string $permission): bool { return \in_array($permission, $this->permissions, true); }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return true; }
        };
    }

    /**
     * Boot pattern copied from
     * {@see \Waaseyaa\Api\Tests\Integration\WorkingCopyPointerAwarenessFlowTest::bootWiredProviders()}.
     *
     * @return array{0: EntityTypeManager, 1: DBALDatabase, 2: TransitionService, 3: RequestAccountContext}
     */
    private function bootWiredProviders(): array
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $db = DBALDatabase::createSqlite();

        $configStorage = new MemoryStorage();
        $configStorage->write('workflows.assignments', [
            'node.article' => 'editorial_forward',
        ]);
        $configFactory = new ConfigFactory($configStorage, $dispatcher);

        $repositoryFactory = static function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, $db): EntityRepositoryInterface {
            $schemaHandler = new SqlSchemaHandler($definition, $db);
            $schemaHandler->ensureTable();
            if ($definition->isRevisionable()) {
                $schemaHandler->ensureRevisionTable();
            }

            $resolver = new SingleConnectionResolver($db);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $definition,
                new SqlStorageDriver($resolver, $definition->getKeys()['id']),
                $dispatcher,
                $definition->isRevisionable() ? new RevisionableStorageDriver($resolver, $definition) : null,
                $db,
            );
        };

        $entityTypeManager = new EntityTypeManager($dispatcher, null, $repositoryFactory);

        $accountContext = new RequestAccountContext();

        $kernelServices = new class ($dispatcher, $entityTypeManager, $configFactory, $accountContext) implements KernelServicesInterface {
            public function __construct(
                private readonly SymfonyEventDispatcherAdapter $dispatcher,
                private readonly EntityTypeManager $entityTypeManager,
                private readonly ConfigFactoryInterface $configFactory,
                private readonly AccountContextInterface $accountContext,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    EntityTypeManager::class, EntityTypeManagerInterface::class => $this->entityTypeManager,
                    ConfigFactoryInterface::class => $this->configFactory,
                    AccountContextInterface::class => $this->accountContext,
                    default => null,
                };
            }
        };

        $nodeProvider = new NodeServiceProvider();
        $nodeProvider->setKernelServices($kernelServices);
        $nodeProvider->register();

        $workflowProvider = new WorkflowServiceProvider();
        $workflowProvider->setKernelServices($kernelServices);
        $workflowProvider->register();

        foreach ($nodeProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }
        foreach ($workflowProvider->getEntityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        $nodeProvider->boot();
        $workflowProvider->boot();

        $entityTypeManager->getRepository('workflow')->save($this->editorialForwardWorkflow());
        $entityTypeManager->getRepository('node_type')->save(new NodeType(['type' => 'article', 'name' => 'Article']));

        /** @var TransitionService $transitionService */
        $transitionService = $workflowProvider->resolve(TransitionService::class);

        return [$entityTypeManager, $db, $transitionService, $accountContext];
    }

    private function editorialForwardWorkflow(): Workflow
    {
        $transitions = DefaultWorkflows::EDITORIAL['transitions'];
        $transitions['revise'] = ['label' => 'Revise', 'from' => ['published'], 'to' => 'draft'];

        foreach ($transitions as $id => $transition) {
            $transition['permission'] = \sprintf('use editorial_forward transition %s', $id);
            $transitions[$id] = $transition;
        }

        $workflow = new Workflow([
            'id' => 'editorial_forward',
            'label' => 'Editorial (test-local, forward drafts)',
            'initial_state' => DefaultWorkflows::EDITORIAL['initial_state'],
            'states' => DefaultWorkflows::EDITORIAL['states'],
            'transitions' => $transitions,
        ]);
        $workflow->enforceIsNew();

        return $workflow;
    }
}
