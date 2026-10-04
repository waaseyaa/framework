<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Api\Audit\ApiAuditQueryAdapter;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\ContentSearch\AtomicRateLimiterAdapter;
use Waaseyaa\Api\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\Api\Controller\AiCatalogController;
use Waaseyaa\Api\Controller\ApiCatalogController;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Api\Controller\FieldAutoSaveController;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\Controller\McpApprovalController;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Controller\NotExposedController;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Controller\WorkflowTransitionController;
use Waaseyaa\Api\Discovery\AiCatalog;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\AiCatalogRouter;
use Waaseyaa\Api\Http\Router\ApiCatalogRouter;
use Waaseyaa\Api\Http\Router\AuditApiRouter;
use Waaseyaa\Api\Http\Router\ContentSearchApiRouter;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Http\Router\FieldAutoSaveApiRouter;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Api\Http\Router\McpApprovalApiRouter;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Api\Http\Router\WorkflowTransitionApiRouter;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\Media\ApiMediaVersionAdapter;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
// Note: AuditQueryInterface is NOT imported at class-level — waaseyaa/audit
// is a require-dev dep. The singleton factory resolves it by string (C-002).
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;
use Waaseyaa\Foundation\Http\Router\SchemaRouter;
use Waaseyaa\Foundation\Http\Router\TranslationRouter;
use Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteExposureInputs;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsAiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsApiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\Version\MediaVersionRepository;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;
use Waaseyaa\Workflows\Read\ActiveWorkflows;
use Waaseyaa\Workflows\Transition\TransitionService;

final class ApiServiceProvider extends ServiceProvider implements ContributesRouteMetadataInterface, HasHttpDomainRoutersInterface, AcceptsApiCatalogEntryProvidersInterface, AcceptsAiCatalogEntryProvidersInterface, ProvidesApiCatalogEntriesInterface, ProvidesAiCatalogEntriesInterface, ConfiguresHttpKernelInterface
{
    private const string CONTENT_SEARCH_PROVIDER = 'Waaseyaa\\Search\\SearchProviderInterface';
    private const string CONTENT_SEARCH_LIMITER = 'Waaseyaa\\Auth\\AtomicRateLimiterInterface';
    private const string CONTENT_SEARCH_REQUEST = 'Waaseyaa\\Search\\SearchRequest';
    private const string CONTENT_SEARCH_FILTERS = 'Waaseyaa\\Search\\SearchFilters';

    /** @var array{identity_max: int, global_max: int, window: int}|false|null */
    private array|false|null $contentSearchConfiguration = null;

    private ?bool $contentSearchAvailable = null;

    private ?bool $mcpAvailable = null;

    /** @var list<ProvidesApiCatalogEntriesInterface> */
    private array $apiCatalogEntryProviders = [];

    /** @var list<ProvidesAiCatalogEntriesInterface> */
    private array $aiCatalogEntryProviders = [];

    private ?ApiCatalog $apiCatalog = null;
    private ?AiCatalog $aiCatalog = null;

    private ?DiscoveryApiHandler $discoveryHandler = null;

    public function configureHttpKernel(HttpKernel $kernel): void
    {
        $this->discoveryHandler = $kernel->getDiscoveryApiHandler();
    }

    public function withApiCatalogEntryProviders(array $providers): void
    {
        $this->apiCatalogEntryProviders = array_values(array_filter(
            $providers,
            static fn(object $provider): bool => $provider instanceof ProvidesApiCatalogEntriesInterface,
        ));
        usort(
            $this->apiCatalogEntryProviders,
            static fn(object $left, object $right): int => $left::class <=> $right::class,
        );
    }

    public function withAiCatalogEntryProviders(array $providers): void
    {
        $this->aiCatalogEntryProviders = array_values(array_filter(
            $providers,
            static fn(object $provider): bool => $provider instanceof ProvidesAiCatalogEntriesInterface,
        ));
        usort(
            $this->aiCatalogEntryProviders,
            static fn(object $left, object $right): int => $left::class <=> $right::class,
        );
    }

    public function apiCatalogEntries(): array
    {
        if (!$this->contentSearchAvailable()) {
            return [];
        }

        return [new ApiCatalogEntry(
            endpoint: new ApiCatalogTarget(
                '/api/content/search',
                'application/vnd.api+json',
                'Public content search',
            ),
        )];
    }

    public function aiCatalogEntries(): array
    {
        $entries = [];
        if ($this->contentSearchAvailable()) {
            $entries[] = new AiCatalogEntry(
                key: 'api:content-search',
                displayName: 'Waaseyaa public content search',
                type: 'application/vnd.api+json',
                path: '/api/content/search',
                description: 'Principal-safe search across intentionally public CMS content.',
                capabilities: ['ContentDiscovery', 'ReadOnlySearch'],
            );
        }

        if ($this->apiCatalog !== null) {
            $entries[] = new AiCatalogEntry(
                key: 'api:catalog',
                displayName: 'Waaseyaa public API catalog',
                type: ApiCatalog::MEDIA_TYPE,
                path: ApiCatalog::PATH,
                description: 'Standards-based index of intentionally public APIs.',
                capabilities: ['PublicApiDiscovery'],
            );
        }

        return $entries;
    }

    public function register(): void
    {
        // Load the owned structural generator at bootstrap, before cold inspection.
        new \ReflectionClass(JsonApiRouteProvider::class);
        $this->bind(NotExposedController::class, static fn(): NotExposedController => new NotExposedController());
        $this->bind(FieldAutoSaveApiRouter::class, function (): FieldAutoSaveApiRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            $access = $this->resolve(EntityAccessHandler::class);
            $registry = $this->resolve(FieldDefinitionRegistryInterface::class);
            if (!$manager instanceof EntityTypeManager || !$access instanceof EntityAccessHandler
                || !$registry instanceof FieldDefinitionRegistryInterface) {
                throw new \RuntimeException('The field autosave execution bindings are invalid.');
            }
            return new FieldAutoSaveApiRouter(new FieldAutoSaveController($manager, $access, $registry));
        });
        $this->bind(JsonApiRouter::class, function (): JsonApiRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            $access = $this->resolve(EntityAccessHandler::class);
            $exposure = $this->resolve(EntityTypeApiExposurePolicy::class);
            $visibility = $this->resolve(InternalFieldVisibilityPolicy::class);
            if (!$manager instanceof EntityTypeManager || !$access instanceof EntityAccessHandler
                || !$exposure instanceof EntityTypeApiExposurePolicy || !$visibility instanceof InternalFieldVisibilityPolicy) {
                throw new \RuntimeException('The JSON:API execution bindings are invalid.');
            }
            return new JsonApiRouter($manager, $access, exposurePolicy: $exposure, internalFieldVisibility: $visibility);
        });
        $this->bind(TranslationRouter::class, function (): TranslationRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            $access = $this->resolve(EntityAccessHandler::class);
            $exposure = $this->resolve(EntityTypeApiExposurePolicy::class);
            $visibility = $this->resolve(InternalFieldVisibilityPolicy::class);
            if (!$manager instanceof EntityTypeManager || !$access instanceof EntityAccessHandler
                || !$exposure instanceof EntityTypeApiExposurePolicy || !$visibility instanceof InternalFieldVisibilityPolicy) {
                throw new \RuntimeException('The translation execution bindings are invalid.');
            }
            return new TranslationRouter($manager, $access, $exposure, $visibility);
        });
        $this->bind(SchemaRouter::class, function (): SchemaRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            $access = $this->resolve(EntityAccessHandler::class);
            $exposure = $this->resolve(EntityTypeApiExposurePolicy::class);
            $registry = $this->kernelServices?->get(FieldDefinitionRegistryInterface::class);
            $authority = $this->kernelServices?->get(FieldSchemaAuthority::class);
            if (!$manager instanceof EntityTypeManager || !$access instanceof EntityAccessHandler
                || !$exposure instanceof EntityTypeApiExposurePolicy
                || ($registry !== null && !$registry instanceof FieldDefinitionRegistryInterface)
                || ($authority !== null && !$authority instanceof FieldSchemaAuthority)) {
                throw new \RuntimeException('The schema execution bindings are invalid.');
            }
            if ($authority === null) {
                $fieldTypes = $this->resolve(FieldTypeManagerInterface::class);
                if (!$fieldTypes instanceof FieldTypeManagerInterface) {
                    throw new \RuntimeException('The boot-scoped field registry is unavailable.');
                }
                $authority = new FieldSchemaAuthority($fieldTypes);
            }
            return new SchemaRouter($manager, $access, $registry, $exposure, $authority);
        });
        $this->bind(WorkflowDefinitionsApiRouter::class, function (): WorkflowDefinitionsApiRouter {
            $active = $this->kernelServices?->get(ActiveWorkflows::class);
            if ($active !== null && !$active instanceof ActiveWorkflows) {
                throw new \RuntimeException('The workflow definitions execution binding is invalid.');
            }
            return new WorkflowDefinitionsApiRouter($active);
        });
        $this->bind(DiscoveryRouter::class, function (): DiscoveryRouter {
            if ($this->discoveryHandler === null) {
                throw new \RuntimeException('The finalized HTTP discovery handler is unavailable.');
            }
            $manager = $this->resolve(EntityTypeManager::class);
            $exposure = $this->resolve(EntityTypeApiExposurePolicy::class);
            if (!$manager instanceof EntityTypeManager || !$exposure instanceof EntityTypeApiExposurePolicy) {
                throw new \RuntimeException('The discovery execution bindings are invalid.');
            }
            return new DiscoveryRouter($this->discoveryHandler, $manager, $exposure);
        });
        $this->bind(OidcClientApiRouter::class, function (): OidcClientApiRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            if (!$manager instanceof EntityTypeManager) {
                throw new \RuntimeException('The OIDC client execution binding is invalid.');
            }
            return new OidcClientApiRouter(new OidcClientController($manager));
        });
        $this->bind(WorkflowTransitionApiRouter::class, function (): WorkflowTransitionApiRouter {
            $manager = $this->resolve(EntityTypeManager::class);
            $transition = $this->resolve(TransitionService::class);
            $access = $this->kernelServices?->get(EntityAccessHandler::class);
            $audit = $this->kernelServices?->get(AuditQueryReadModelInterface::class);
            if (!$manager instanceof EntityTypeManager || !$transition instanceof TransitionService
                || ($access !== null && !$access instanceof EntityAccessHandler)
                || ($audit !== null && !$audit instanceof AuditQueryReadModelInterface)) {
                throw new \RuntimeException('The workflow execution bindings are invalid.');
            }
            return new WorkflowTransitionApiRouter(new WorkflowTransitionController($manager, $access, $transition, auditQuery: $audit));
        });
        $this->bind(ContentSearchApiRouter::class, fn(): ContentSearchApiRouter => $this->contentSearchRouter());
        $this->bind(McpAdminApiRouter::class, function (): McpAdminApiRouter {
            $registry = $this->kernelServices?->get(ToolRegistryReadModelInterface::class);
            $config = $this->kernelServices?->get(ServerConfigReadModelInterface::class);
            if (($registry !== null && !$registry instanceof ToolRegistryReadModelInterface)
                || ($config !== null && !$config instanceof ServerConfigReadModelInterface)) {
                throw new \RuntimeException('The MCP admin execution bindings are invalid.');
            }
            return new McpAdminApiRouter(new McpAdminController($registry, $config));
        });
        $this->bind(McpApprovalApiRouter::class, function (): McpApprovalApiRouter {
            // Keep the existing best-effort telemetry policy and lazy store boundary.
            $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);
            return new McpApprovalApiRouter(new McpApprovalController(
                storeResolver: fn(): object => $this->resolve(OperationApprovalStoreInterface::class),
                allowedOrigins: $this->corsOrigins(),
                allowSelfApproval: $this->approvalAllowsSelfApproval(),
                dispatcher: $dispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface ? $dispatcher : null,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            ));
        });
        $this->bind(AuditApiRouter::class, function (): AuditApiRouter {
            $model = $this->kernelServices?->get(AuditQueryReadModelInterface::class);
            if ($model !== null && !$model instanceof AuditQueryReadModelInterface) {
                throw new \RuntimeException('The audit execution binding is invalid.');
            }
            return new AuditApiRouter(new AuditQueryController($model));
        });
        $this->bind(ApiCatalogRouter::class, function (): ApiCatalogRouter {
            if ($this->apiCatalog === null) {
                throw new \RuntimeException('The boot-finalized API catalog is unavailable.');
            }
            return new ApiCatalogRouter(new ApiCatalogController($this->apiCatalog));
        });
        $this->bind(AiCatalogRouter::class, function (): AiCatalogRouter {
            if ($this->aiCatalog === null) {
                throw new \RuntimeException('The boot-finalized AI catalog is unavailable.');
            }
            return new AiCatalogRouter(new AiCatalogController($this->aiCatalog));
        });
        $this->bind(SchedulerAdminApiRouter::class, function (): SchedulerAdminApiRouter {
            $schedule = $this->resolve(ScheduleInterface::class);
            $state = $this->resolve(ScheduleStateRepository::class);
            $runner = $this->resolve(ScheduleRunner::class);
            if (!$schedule instanceof ScheduleInterface || !$state instanceof ScheduleStateRepository || !$runner instanceof ScheduleRunner) {
                throw new \RuntimeException('The required scheduler execution bindings are invalid.');
            }
            return new SchedulerAdminApiRouter(new SchedulerController($schedule, $state, $runner));
        });
        $this->bind(NotificationAdminApiRouter::class, function (): NotificationAdminApiRouter {
            $dispatcher = $this->resolve(NotificationDispatcher::class);
            $reader = $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class);
            if (!$dispatcher instanceof NotificationDispatcher || !$reader instanceof \Waaseyaa\Access\User\UserInternalFieldReaderInterface) {
                throw new \RuntimeException('The required notification execution bindings are invalid.');
            }
            return new NotificationAdminApiRouter(new NotificationController($dispatcher, $reader));
        });

        $this->bind(MediaVersionApiRouter::class, function (): MediaVersionApiRouter {
            $model = $this->kernelServices?->get(MediaVersionReadModelInterface::class);
            if ($model !== null && !$model instanceof MediaVersionReadModelInterface) {
                throw new \RuntimeException('The media execution binding is invalid.');
            }
            return new MediaVersionApiRouter(new MediaVersionController($model));
        });
        $this->bind(MercureMonitorApiRouter::class, function (): MercureMonitorApiRouter {
            $inspector = $this->kernelServices?->get(ChannelInspectorInterface::class);
            $stream = $this->kernelServices?->get(EventStreamReadModelInterface::class);
            $observer = $this->kernelServices?->get(SubscriberObserverInterface::class);
            if (($inspector !== null && !$inspector instanceof ChannelInspectorInterface)
                || ($stream !== null && !$stream instanceof EventStreamReadModelInterface)
                || ($observer !== null && !$observer instanceof SubscriberObserverInterface)
                || ($inspector === null && $stream === null && $observer === null)) {
                throw new \RuntimeException('The monitor execution bindings are unavailable or invalid.');
            }
            return new MercureMonitorApiRouter(new MercureMonitorController($inspector, $stream, $observer));
        });

        $this->bind(QueueAdminApiRouter::class, function (): QueueAdminApiRouter {
            $failedJobs = $this->resolve(FailedJobRepositoryInterface::class);
            $queue = $this->resolve(QueueInterface::class);
            if (!$failedJobs instanceof FailedJobRepositoryInterface || !$queue instanceof QueueInterface) {
                throw new \RuntimeException('The required queue execution bindings are invalid.');
            }
            // Retain the existing failed-only fallback for optional transport.
            $transport = $this->resolveOptional(TransportInterface::class);
            return new QueueAdminApiRouter(new QueueController(
                $failedJobs,
                $queue,
                $transport instanceof TransportInterface ? $transport : null,
            ));
        });

        $this->singleton(EntityTypeApiExposurePolicy::class, function (): EntityTypeApiExposurePolicy {
            $manager = $this->resolve(EntityTypeManager::class);
            \assert($manager instanceof EntityTypeManager);

            return EntityTypeApiExposurePolicy::fromConfig($manager, $this->config);
        });

        $this->singleton(InternalFieldVisibilityPolicy::class, function (): InternalFieldVisibilityPolicy {
            return InternalFieldVisibilityPolicy::fromConfig($this->config);
        });

        // OCAP audit log substrate (ocap-audit-log-substrate-01KSEFTF WP03).
        // Bind the api-local read-model interface to the adapter that bridges
        // L0 audit contracts (AuditQueryInterface) into L4 DTOs.
        // DEAD-CODE GUARD: removing this singleton causes OcapAuditEndpointTest
        // to receive empty {data: [], meta: {total: 0}} — the test's count()
        // assertion fails, proving the binding is live.
        // waaseyaa/audit is in require-dev (C-002 / NFR-002) so AuditQueryInterface
        // is resolved by string to avoid a hard class-level import that would
        // crash kernel boot on installs without waaseyaa/audit.
        $this->singleton(AuditQueryReadModelInterface::class, function (): AuditQueryReadModelInterface {
            /** @var \Waaseyaa\Audit\Contract\AuditQueryInterface $auditQuery */
            $auditQuery = $this->resolve(\Waaseyaa\Audit\Contract\AuditQueryInterface::class);

            return new ApiAuditQueryAdapter($auditQuery);
        });

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ): bind the API
        // read-model for MediaVersion. The adapter resolves MediaVersionRepository
        // (L2 media) and GateInterface at boot-time via resolveOptional so that
        // installs without the media package skip cleanly. The GateInterface is
        // optional: when absent (no media access policy registered) all versions
        // are accessible to authenticated accounts (open-by-default per spec).
        // Bind only when MediaVersionRepository is resolvable (media package present).
        // The httpDomainRouters() block uses resolveOptional to skip gracefully when absent.
        $repo = $this->resolveOptional(MediaVersionRepository::class);
        if ($repo instanceof MediaVersionRepository) {
            $gate = $this->resolveOptional(GateInterface::class);
            $this->singleton(MediaVersionReadModelInterface::class, fn(): ApiMediaVersionAdapter => new ApiMediaVersionAdapter(
                repo: $repo,
                gate: $gate instanceof GateInterface ? $gate : null,
            ));
        }
    }

    public function boot(): void
    {
        // Resolve after every provider/app entity registration has completed so
        // strict allowlist validation fails during kernel boot, before routing.
        $policy = $this->resolve(EntityTypeApiExposurePolicy::class);
        $routeInputs = $this->resolveOptional(RouteExposureInputs::class);
        $this->resolve(InternalFieldVisibilityPolicy::class);
        // Finalize optional install facts at boot, even when both catalogs are
        // disabled. Later route reads must not discover a different install.
        $contentSearchAvailable = $this->contentSearchAvailable();
        $mcpInstalled = $this->mcpInstalled();
        $this->apiCatalog = $this->buildApiCatalog();
        $this->aiCatalog = $this->buildAiCatalog();
        if ($policy instanceof EntityTypeApiExposurePolicy && $routeInputs instanceof RouteExposureInputs) {
            $routeInputs->publish($policy->effectiveMap(), [
                'api.route.content_search' => $contentSearchAvailable,
                'api.route.mcp' => $mcpInstalled,
                'api.route.catalog' => $this->apiCatalog !== null,
                'api.route.ai_catalog' => $this->aiCatalog !== null,
            ]);
        }
    }

    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        $exposurePolicy = $this->exposurePolicy($httpKernel->getEntityTypeManager());
        $routers = [
            new DiscoveryRouter(
                $httpKernel->getDiscoveryApiHandler(),
                $httpKernel->getEntityTypeManager(),
                $exposurePolicy,
            ),
        ];

        if ($this->contentSearchAvailable()) {
            $routers[] = $this->contentSearchRouter();
        }

        if ($this->apiCatalog !== null) {
            $routers[] = new ApiCatalogRouter(new ApiCatalogController($this->apiCatalog));
        }
        if ($this->aiCatalog !== null) {
            $routers[] = new AiCatalogRouter(new AiCatalogController($this->aiCatalog));
        }

        // M4B WP01 (+ #1576 follow-up): admin queue dashboard. Pull the queue
        // services through the kernel-services resolver — they're bound by
        // QueueServiceProvider (Layer 0) and routed through here in Layer 4,
        // the same indirection pattern used by AuthOidcRouteServiceProvider
        // for auth/oidc. The TransportInterface is optional: when absent the
        // controller falls back to the M4B failed-only response shape so the
        // admin dashboard keeps working on installs without an SQL transport.
        $failedJobs = $this->resolveOptional(FailedJobRepositoryInterface::class);
        $queue = $this->resolveOptional(QueueInterface::class);
        $transportCandidate = $this->resolveOptional(TransportInterface::class);
        $transport = $transportCandidate instanceof TransportInterface ? $transportCandidate : null;
        if ($failedJobs instanceof FailedJobRepositoryInterface && $queue instanceof QueueInterface) {
            $routers[] = new QueueAdminApiRouter(new QueueController($failedJobs, $queue, $transport));
        }

        // M4B WP02: admin scheduler dashboard. Same indirection pattern as
        // the queue block — SchedulerServiceProvider (Layer 0) binds the
        // three services; if any of them is absent (slimmed-down install),
        // we skip wiring the router rather than crashing kernel boot.
        $schedule = $this->resolveOptional(ScheduleInterface::class);
        $schedulerState = $this->resolveOptional(ScheduleStateRepository::class);
        $schedulerRunner = $this->resolveOptional(ScheduleRunner::class);
        if (
            $schedule instanceof ScheduleInterface
            && $schedulerState instanceof ScheduleStateRepository
            && $schedulerRunner instanceof ScheduleRunner
        ) {
            $routers[] = new SchedulerAdminApiRouter(
                new SchedulerController($schedule, $schedulerState, $schedulerRunner),
            );
        }

        // M4C WP01: admin notifications dashboard. Same indirection pattern as
        // the queue + scheduler blocks — NotificationServiceProvider (Layer 3)
        // binds the dispatcher; if absent (slimmed-down install lacking
        // notification wiring) we skip the router cleanly rather than crash.
        $notificationDispatcher = $this->resolveOptional(NotificationDispatcher::class);
        if ($notificationDispatcher instanceof NotificationDispatcher) {
            $routers[] = new NotificationAdminApiRouter(
                new NotificationController(
                    $notificationDispatcher,
                    $this->resolve(\Waaseyaa\Access\User\UserInternalFieldReaderInterface::class),
                ),
            );
        }

        // CW-v1 WP-4 (#1920): workflow transition endpoints. Same
        // indirection pattern as the queue/scheduler/notification blocks
        // above — WorkflowServiceProvider (Layer 3) binds TransitionService; if the
        // binding is absent (a core-only install without waaseyaa/workflows
        // wired) we skip the router AND the routes (see routes() below)
        // rather than crashing boot or routing to a controller that could
        // not be constructed (design decision 1,
        // docs/history/plans/2026-07-10-content-workflow-wp4.md).
        $transitionService = $this->resolveOptional(TransitionService::class);
        if ($transitionService instanceof TransitionService) {
            $accessHandler = $this->resolveOptional(EntityAccessHandler::class);
            $auditQuery = $this->resolveOptional(AuditQueryReadModelInterface::class);
            $routers[] = new WorkflowTransitionApiRouter(new WorkflowTransitionController(
                $httpKernel->getEntityTypeManager(),
                $accessHandler instanceof EntityAccessHandler ? $accessHandler : null,
                $transitionService,
                auditQuery: $auditQuery instanceof AuditQueryReadModelInterface ? $auditQuery : null,
            ));
        }

        // M5D WP01: Mercure broadcast monitor dashboard. Same indirection
        // pattern as queue/scheduler/notification blocks — all three deps are
        // bound by `MercureMonitorServiceProvider` (Layer 0 foundation). When
        // any binding is absent (slimmed-down install or monitor disabled via
        // `broadcasting.monitor.enabled = false`) the controller falls back to
        // zeroed empty-shape responses rather than crashing boot (FR-006).
        // The router is wired when at least one dep is resolvable; the
        // controller handles individual nulls internally.
        $inspector = $this->resolveOptional(ChannelInspectorInterface::class);
        $streamModel = $this->resolveOptional(EventStreamReadModelInterface::class);
        $observer = $this->resolveOptional(SubscriberObserverInterface::class);
        if (
            $inspector instanceof ChannelInspectorInterface
            || $streamModel instanceof EventStreamReadModelInterface
            || $observer instanceof SubscriberObserverInterface
        ) {
            $routers[] = new MercureMonitorApiRouter(
                new MercureMonitorController(
                    $inspector instanceof ChannelInspectorInterface ? $inspector : null,
                    $streamModel instanceof EventStreamReadModelInterface ? $streamModel : null,
                    $observer instanceof SubscriberObserverInterface ? $observer : null,
                ),
            );
        }

        // OCAP audit log substrate (ocap-audit-log-substrate-01KSEFTF WP03).
        // AuditQueryReadModelInterface is bound in register() above; resolve
        // it optionally so slimmed-down installs lacking waaseyaa/audit boot
        // cleanly. The controller handles null read-model → empty response.
        $auditReadModel = $this->resolveOptional(AuditQueryReadModelInterface::class);
        $routers[] = new AuditApiRouter(
            new AuditQueryController(
                $auditReadModel instanceof AuditQueryReadModelInterface ? $auditReadModel : null,
            ),
        );

        if ($this->mcpInstalled()) {
            $mcpRegistry = $this->resolveOptional(ToolRegistryReadModelInterface::class);
            $mcpConfig = $this->resolveOptional(ServerConfigReadModelInterface::class);
            $routers[] = new McpAdminApiRouter(new McpAdminController(
                registry: $mcpRegistry instanceof ToolRegistryReadModelInterface ? $mcpRegistry : null,
                config: $mcpConfig instanceof ServerConfigReadModelInterface ? $mcpConfig : null,
            ));

            // MCP approval decision surface (#2177 F1 C1b). The store is
            // resolved LAZILY per request through the closure — resolving it
            // here would ensure the approval schema at every boot, and a
            // deployment that never uses the write tier must pay nothing.
            // resolve() (not resolveOptional) is deliberate: resolveOptional
            // swallows every RuntimeException, which would misreport a bound
            // store's runtime failure (ApprovalStoreException IS a
            // RuntimeException) as "not bound". The controller tells the two
            // apart by the container's exact "No binding registered for"
            // sentinel and fails closed either way.
            $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
            $logger = $this->resolveOptional(LoggerInterface::class);
            $routers[] = new McpApprovalApiRouter(new McpApprovalController(
                storeResolver: function (): OperationApprovalStoreInterface {
                    $store = $this->resolve(OperationApprovalStoreInterface::class);
                    \assert($store instanceof OperationApprovalStoreInterface);

                    return $store;
                },
                allowedOrigins: $this->corsOrigins(),
                allowSelfApproval: $this->approvalAllowsSelfApproval(),
                dispatcher: $dispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface ? $dispatcher : null,
                logger: $logger instanceof LoggerInterface ? $logger : null,
            ));
        }

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ): media version
        // read API. The read-model is bound in register() above; if the media
        // package is absent (slimmed-down install) the controller falls back to
        // empty/404 shapes without crashing boot. Routes are registered in
        // routes() below, gated by _authenticated.
        $mediaVersionReadModel = $this->resolveOptional(MediaVersionReadModelInterface::class);
        $routers[] = new MediaVersionApiRouter(
            new MediaVersionController(
                $mediaVersionReadModel instanceof MediaVersionReadModelInterface ? $mediaVersionReadModel : null,
            ),
        );

        // WP05: OIDC client CRUD admin API. The oidc_client entity type is
        // registered by OidcServiceProvider when the opt-in domain is installed.
        if ($httpKernel->getEntityTypeManager()->hasDefinition('oidc_client')) {
            $routers[] = new OidcClientApiRouter(new OidcClientController($httpKernel->getEntityTypeManager()));
        }

        return $routers;
    }

    /** @return iterable<RouteDefinition> Complete API declarations from copied finalized inputs. */
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        yield from $this->declarations($context, true);
    }

    /** @return iterable<RouteDefinition> */
    private function declarations(RouteContributionContext $context, bool $requestTerminals): iterable
    {
        foreach (['api.route.content_search', 'api.route.mcp', 'api.route.catalog', 'api.route.ai_catalog'] as $fact) {
            if (!array_key_exists($fact, $context->capabilities)) {
                throw new RouteCompositionException('inputs-unavailable', 'Finalized API route availability is unavailable.');
            }
        }
        $ordinal = 0;
        if ($context->capabilities['api.route.content_search']) {
            yield $this->definition($context, $ordinal++, 'api.content_search', '/api/content/search', 'Waaseyaa\Api\Controller\ContentSearchController::search', ['GET', 'HEAD'], [], ['_public' => true], 100, $requestTerminals);
        }
        foreach (JsonApiRouteProvider::routeDefinitions($context, ordinal: $ordinal, requestTerminals: $requestTerminals) as $definition) {
            yield $definition;
            $ordinal++;
        }
        foreach ([
            ['api.route.catalog', 'api.catalog', '/.well-known/api-catalog', 'Waaseyaa\Api\Controller\ApiCatalogController::serve'],
            ['api.route.ai_catalog', 'ai.catalog', '/.well-known/ai-catalog.json', 'Waaseyaa\Api\Controller\AiCatalogController::serve'],
        ] as [$fact, $name, $path, $controller]) {
            if ($context->capabilities[$fact]) {
                yield $this->definition($context, $ordinal++, $name, $path, $controller, ['GET', 'HEAD'], [], ['_public' => true], 10, $requestTerminals);
            }
        }
        if ($context->capabilities['service:Waaseyaa\Workflows\Transition\TransitionService'] ?? false) {
            foreach (JsonApiRouteProvider::routeDefinitions($context, workflow: true, ordinal: $ordinal, requestTerminals: $requestTerminals) as $definition) {
                yield $definition;
                $ordinal++;
            }
        }
        $oidcPresent = in_array('oidc_client', array_column($context->entities, 'id'), true);
        foreach ([
            ['api.schema.show', '/api/schema/{entity_type}', 'Waaseyaa\\Api\\Controller\\SchemaController::show', ['GET'], [], ['_authenticated' => true], ''],
            ['api.workflow_definitions.list', '/api/workflow-definitions', 'Waaseyaa\\Api\\Workflow\\WorkflowDefinitionsController::list', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.queue.jobs.index', '/api/queue/jobs', 'Waaseyaa\\Api\\Controller\\QueueController::index', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.queue.jobs.retry', '/api/queue/jobs/{id}/retry', 'Waaseyaa\\Api\\Controller\\QueueController::retry', ['POST'], [], ['_role' => 'admin'], ''],
            ['api.queue.jobs.discard', '/api/queue/jobs/{id}/discard', 'Waaseyaa\\Api\\Controller\\QueueController::discard', ['POST'], [], ['_role' => 'admin'], ''],
            ['api.scheduler.tasks.index', '/api/scheduler/tasks', 'Waaseyaa\\Api\\Controller\\SchedulerController::index', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.scheduler.tasks.trigger', '/api/scheduler/tasks/{name}/trigger', 'Waaseyaa\\Api\\Controller\\SchedulerController::trigger', ['POST'], [], ['_role' => 'admin'], ''],
            ['api.notification.channels.index', '/api/notification/channels', 'Waaseyaa\\Api\\Controller\\NotificationController::index', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.notification.channels.test', '/api/notification/channels/{type}/test', 'Waaseyaa\\Api\\Controller\\NotificationController::test', ['POST'], [], ['_role' => 'admin'], ''],
            ['api.mercure.monitor.channels', '/api/mercure/channels', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::channels', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.mercure.monitor.events', '/api/mercure/events', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::events', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.mercure.monitor.subscribers', '/api/mercure/subscribers', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::subscribers', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.media.versions.index', '/api/media/{uuid}/versions', 'Waaseyaa\\Api\\Controller\\MediaVersionController::index', ['GET'], [], ['_authenticated' => true], ''],
            ['api.media.versions.show', '/api/media/{uuid}/versions/{vid}', 'Waaseyaa\\Api\\Controller\\MediaVersionController::show', ['GET'], [], ['_authenticated' => true], ''],
            ['api.audit.events.index', '/api/audit/events', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index', ['GET'], [], ['_role' => 'admin'], ''],
            ['api.mcp.admin.tools.index', '/api/mcp/tools', 'Waaseyaa\\Api\\Controller\\McpAdminController::tools', ['GET'], [], ['_role' => 'admin'], 'api.route.mcp'],
            ['api.mcp.admin.tools.show', '/api/mcp/tools/{name}', 'Waaseyaa\\Api\\Controller\\McpAdminController::tool', ['GET'], [], ['_role' => 'admin'], 'api.route.mcp'],
            ['api.mcp.admin.server-config', '/api/mcp/server-config', 'Waaseyaa\\Api\\Controller\\McpAdminController::serverConfig', ['GET'], [], ['_role' => 'admin'], 'api.route.mcp'],
            ['api.mcp.approvals.index', '/api/mcp/approvals', 'Waaseyaa\\Api\\Controller\\McpApprovalController::index', ['GET'], [], ['_authenticated' => true, '_session' => ['waaseyaa_uid'], '_permission' => 'mcp.approval.view'], 'api.route.mcp'],
            ['api.mcp.approvals.decision', '/api/mcp/approvals/{id}/decision', 'Waaseyaa\\Api\\Controller\\McpApprovalController::decide', ['POST'], [], ['_authenticated' => true, '_session' => ['waaseyaa_uid'], '_permission' => 'mcp.approval.decide', '_csrf' => true], 'api.route.mcp'],
            ['api.oidc-clients.index', '/api/oidc-clients', 'Waaseyaa\\Api\\Controller\\OidcClientController::index', ['GET'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.oidc-clients.create', '/api/oidc-clients', 'Waaseyaa\\Api\\Controller\\OidcClientController::create', ['POST'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.oidc-clients.show', '/api/oidc-clients/{id}', 'Waaseyaa\\Api\\Controller\\OidcClientController::show', ['GET'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.oidc-clients.update', '/api/oidc-clients/{id}', 'Waaseyaa\\Api\\Controller\\OidcClientController::update', ['PATCH'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.oidc-clients.delete', '/api/oidc-clients/{id}', 'Waaseyaa\\Api\\Controller\\OidcClientController::delete', ['DELETE'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.oidc-clients.regenerate-secret', '/api/oidc-clients/{id}/regenerate-secret', 'Waaseyaa\\Api\\Controller\\OidcClientController::regenerateSecret', ['POST'], [], ['_role' => 'admin'], 'entity:oidc_client'],
            ['api.classification.policies.index', '/api/classification/policies', 'Waaseyaa\\Api\\JsonApiController::index', ['GET'], ['_entity_type' => 'retention_policy'], ['_role' => 'governance-viewer,admin'], ''],
            ['api.classification.policies.show', '/api/classification/policies/{id}', 'Waaseyaa\\Api\\JsonApiController::show', ['GET'], ['_entity_type' => 'retention_policy'], ['_role' => 'governance-viewer,admin'], ''],
            ['api.classification.policies.store', '/api/classification/policies', 'Waaseyaa\\Api\\JsonApiController::store', ['POST'], ['_entity_type' => 'retention_policy'], ['_role' => 'admin'], ''],
            ['api.classification.policies.update', '/api/classification/policies/{id}', 'Waaseyaa\\Api\\JsonApiController::update', ['PATCH'], ['_entity_type' => 'retention_policy'], ['_role' => 'admin'], ''],
            ['api.classification.policies.destroy', '/api/classification/policies/{id}', 'Waaseyaa\\Api\\JsonApiController::destroy', ['DELETE'], ['_entity_type' => 'retention_policy'], ['_role' => 'admin'], ''],
        ] as [$name, $path, $controller, $methods, $defaults, $options, $gate]) {
            if (($gate === 'api.route.mcp' && !$context->capabilities[$gate]) || ($gate === 'entity:oidc_client' && !$oidcPresent)) {
                continue;
            }
            yield $this->definition($context, $ordinal++, $name, $path, $controller, $methods, $defaults, $options, 0, $requestTerminals);
        }
    }

    private function definition(RouteContributionContext $context, int $ordinal, string $name, string $path, string $controller, array $methods, array $defaults, array $options, int $priority, bool $requestTerminals): RouteDefinition
    {
        if ($requestTerminals) {
            [$class, $method] = explode('::', $controller, 2);
            $class = match ($class) {
                'Waaseyaa\Api\Controller\ContentSearchController' => 'Waaseyaa\Api\Http\Router\ContentSearchApiRouter',
                'Waaseyaa\Api\Controller\ApiCatalogController' => 'Waaseyaa\Api\Http\Router\ApiCatalogRouter',
                'Waaseyaa\Api\Controller\AiCatalogController' => 'Waaseyaa\Api\Http\Router\AiCatalogRouter',
                'Waaseyaa\Api\Controller\SchemaController' => 'Waaseyaa\Foundation\Http\Router\SchemaRouter',
                'Waaseyaa\Api\Workflow\WorkflowDefinitionsController' => 'Waaseyaa\Foundation\Http\Router\WorkflowDefinitionsApiRouter',
                'Waaseyaa\Api\Controller\QueueController' => 'Waaseyaa\Api\Http\Router\QueueAdminApiRouter',
                'Waaseyaa\Api\Controller\SchedulerController' => 'Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter',
                'Waaseyaa\Api\Controller\NotificationController' => 'Waaseyaa\Api\Http\Router\NotificationAdminApiRouter',
                'Waaseyaa\Api\Controller\MercureMonitorController' => 'Waaseyaa\Api\Http\Router\MercureMonitorApiRouter',
                'Waaseyaa\Api\Controller\MediaVersionController' => 'Waaseyaa\Api\Http\Router\MediaVersionApiRouter',
                'Waaseyaa\Api\Controller\AuditQueryController' => 'Waaseyaa\Api\Http\Router\AuditApiRouter',
                'Waaseyaa\Api\Controller\McpAdminController' => 'Waaseyaa\Api\Http\Router\McpAdminApiRouter',
                'Waaseyaa\Api\Controller\McpApprovalController' => 'Waaseyaa\Api\Http\Router\McpApprovalApiRouter',
                'Waaseyaa\Api\Controller\OidcClientController' => 'Waaseyaa\Api\Http\Router\OidcClientApiRouter',
                'Waaseyaa\Api\JsonApiController' => 'Waaseyaa\Foundation\Http\Router\JsonApiRouter',
                default => throw new \LogicException('Unsupported API request terminal.'),
            };
            $method = match ($class) {
                'Waaseyaa\Api\Http\Router\ContentSearchApiRouter', 'Waaseyaa\Api\Http\Router\ApiCatalogRouter', 'Waaseyaa\Api\Http\Router\AiCatalogRouter', 'Waaseyaa\Foundation\Http\Router\JsonApiRouter' => 'handle',
                'Waaseyaa\Api\Http\Router\OidcClientApiRouter' => $method === 'destroy' ? 'delete' : $method,
                default => $method,
            };
            $controller = $class . '::' . $method;
        }
        return new RouteDefinition($name, $path, HandlerReference::fromString('class:' . $controller), methods: $methods, defaults: $defaults, options: $options, priority: $priority, sourceId: $context->sourceId, ordinal: $ordinal);
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        // Bare-provider compatibility has no kernel publication. Copy its existing
        // finalized policy and gates once, then replay the same structural table.
        $policy = $this->exposurePolicy($entityTypeManager);
        $entities = [];
        foreach ($entityTypeManager->getDefinitions() as $definition) {
            $entities[] = ['id' => $definition->id(), 'api_exposed' => EntityTypeApiExposure::isExposed($definition, $policy)];
        }
        $context = new RouteContributionContext(self::class, 0, capabilities: [
            'api.route.content_search' => $this->contentSearchAvailable(),
            'api.route.mcp' => $this->mcpInstalled(),
            'api.route.catalog' => $this->apiCatalog !== null,
            'api.route.ai_catalog' => $this->aiCatalog !== null,
            'service:Waaseyaa\Workflows\Transition\TransitionService' => $this->resolveOptional(TransitionService::class) instanceof TransitionService,
        ], entities: $entities);
        foreach ($this->declarations($context, false) as $definition) {
            $route = new RouteMetadataCompiler()->compileRoute($definition);
            $options = $route->getOptions();
            if ($definition->priority === 0) {
                unset($options['_waaseyaa_priority']);
            }
            $route->setOptions($options);
            $route->setDefault('_controller', match ($definition->name) {
                'api.catalog' => 'api.catalog',
                'ai.catalog' => 'ai.catalog',
                default => $definition->handler->target === NotExposedController::class
                    ? new NotExposedController()->__invoke(...)
                    : $definition->handler->target . '::' . $definition->handler->method,
            });
            $router->addRoute($definition->name, $route);
        }
    }

    private function exposurePolicy(EntityTypeManager $entityTypeManager): EntityTypeApiExposurePolicy
    {
        $resolved = $this->resolveOptional(EntityTypeApiExposurePolicy::class);
        if ($resolved instanceof EntityTypeApiExposurePolicy) {
            return $resolved;
        }

        // Bare unit construction may invoke routes() without the provider
        // registration pass. Preserve that supported construction site while
        // production boot resolves the registered singleton above.
        return EntityTypeApiExposurePolicy::fromConfig($entityTypeManager, $this->config);
    }

    private function buildApiCatalog(): ?ApiCatalog
    {
        $section = $this->config['api_catalog'] ?? [];
        if (!is_array($section)) {
            throw new ConfigException('api_catalog must be a configuration map.');
        }
        if (array_key_exists('base_url', $section) && !is_string($section['base_url'])) {
            throw new ConfigException('api_catalog.base_url must be a string.');
        }

        $environmentUrl = getenv('APP_URL');
        $configuredBaseUrl = $section['base_url']
            ?? ($environmentUrl !== false ? $environmentUrl : null);
        $baseUrl = is_string($configuredBaseUrl) ? trim($configuredBaseUrl) : '';

        $enabled = $baseUrl !== '';
        if (array_key_exists('enabled', $section)) {
            if (!is_bool($section['enabled'])) {
                throw new ConfigException('api_catalog.enabled must be a boolean.');
            }
            $enabled = $section['enabled'];
        }

        if (!$enabled) {
            return null;
        }
        if ($baseUrl === '') {
            throw new ConfigException('api_catalog.base_url must be configured when the catalog is enabled.');
        }

        $entries = [];
        foreach ($this->apiCatalogEntryProviders as $provider) {
            foreach ($provider->apiCatalogEntries() as $entry) {
                $entries[] = $entry;
            }
        }
        if ($entries === []) {
            return null;
        }

        try {
            return new ApiCatalog($baseUrl, $entries);
        } catch (\InvalidArgumentException $exception) {
            throw new ConfigException('api_catalog.base_url must be a canonical HTTPS URL.', previous: $exception);
        }
    }

    private function buildAiCatalog(): ?AiCatalog
    {
        $section = $this->config['ai_catalog'] ?? [];
        if (!is_array($section)) {
            throw new ConfigException('ai_catalog must be a configuration map.');
        }
        $unknown = array_diff(array_keys($section), ['enabled', 'base_url', 'representative_queries']);
        if ($unknown !== []) {
            throw new ConfigException('ai_catalog contains unsupported configuration keys.');
        }
        if (array_key_exists('enabled', $section) && !is_bool($section['enabled'])) {
            throw new ConfigException('ai_catalog.enabled must be a boolean.');
        }
        if (array_key_exists('base_url', $section) && !is_string($section['base_url'])) {
            throw new ConfigException('ai_catalog.base_url must be a string.');
        }
        if (array_key_exists('representative_queries', $section) && !is_array($section['representative_queries'])) {
            throw new ConfigException('ai_catalog.representative_queries must be a configuration map.');
        }
        try {
            /** @var array<mixed, mixed> $configuredQueries */
            $configuredQueries = $section['representative_queries'] ?? [];
            AiCatalog::assertRepresentativeQueries($configuredQueries);
        } catch (\InvalidArgumentException $exception) {
            throw new ConfigException('ai_catalog.representative_queries is invalid.', previous: $exception);
        }

        $entries = [];
        foreach ($this->aiCatalogEntryProviders as $provider) {
            foreach ($provider->aiCatalogEntries() as $entry) {
                $entries[] = $entry;
            }
        }
        try {
            AiCatalog::assertEntryQueryCompatibility($entries, $configuredQueries);
        } catch (\InvalidArgumentException $exception) {
            throw new ConfigException('ai_catalog configuration is invalid.', previous: $exception);
        }

        if (($section['enabled'] ?? false) !== true) {
            return null;
        }

        $environmentUrl = getenv('APP_URL');
        $configuredBaseUrl = $section['base_url'] ?? ($environmentUrl !== false ? $environmentUrl : null);
        $baseUrl = is_string($configuredBaseUrl) ? trim($configuredBaseUrl) : '';
        if ($baseUrl === '') {
            throw new ConfigException('ai_catalog.base_url must be configured when the catalog is enabled.');
        }

        if ($entries === []) {
            return null;
        }

        try {
            /** @var array<mixed, mixed> $representativeQueries */
            $representativeQueries = $section['representative_queries'] ?? [];

            return new AiCatalog($baseUrl, $entries, $representativeQueries);
        } catch (\InvalidArgumentException $exception) {
            throw new ConfigException('ai_catalog configuration is invalid.', previous: $exception);
        }
    }

    private function mcpInstalled(): bool
    {
        return $this->mcpAvailable ??= class_exists('Waaseyaa\\Mcp\\McpServiceProvider');
    }

    private function contentSearchRouter(): ContentSearchApiRouter
    {
        $configuration = $this->contentSearchConfiguration();
        if ($configuration === false) {
            throw new \RuntimeException('Public content search is disabled.');
        }
        $services = $this->kernelServices;

        // The closures deliberately capture only the kernel-services bus
        // and immutable scalar config. Resolving either database-backed
        // service while building routes violates the request lifecycle and
        // can lose rate-limit writes (#1611).
        $loggerResolver = static function () use ($services): ?LoggerInterface {
            try {
                $logger = $services?->get(LoggerInterface::class);
            } catch (\Throwable) {
                return null;
            }

            return $logger instanceof LoggerInterface ? $logger : null;
        };
        return new ContentSearchApiRouter(
            static function () use ($services, $configuration, $loggerResolver): ContentSearchController {
                if ($services === null) {
                    throw new \RuntimeException('The kernel-services bus is unavailable.');
                }
                $provider = $services->get(self::CONTENT_SEARCH_PROVIDER);
                $limiter = $services->get(self::CONTENT_SEARCH_LIMITER);
                if ($provider === null || $limiter === null) {
                    throw new \RuntimeException('The optional public content search service binding is unavailable.');
                }

                return new ContentSearchController(
                    provider: new SearchPackageContentSearchAdapter($provider),
                    limiter: new AtomicRateLimiterAdapter($limiter),
                    identityMaxAttempts: $configuration['identity_max'],
                    globalMaxAttempts: $configuration['global_max'],
                    windowSeconds: $configuration['window'],
                    logger: $loggerResolver(),
                );
            },
            $loggerResolver,
        );
    }

    /**
     * One scalar install gate owns both route and domain-router registration.
     * `interface_exists()` is the Composer-autoload presence signal for these
     * suggested packages. Do not replace it with eager resolve(): both services
     * reach DatabaseInterface and must be resolved inside the request (#1611).
     */
    private function contentSearchAvailable(): bool
    {
        if ($this->contentSearchAvailable !== null) {
            return $this->contentSearchAvailable;
        }

        return $this->contentSearchAvailable = $this->contentSearchConfiguration() !== false
            && interface_exists(self::CONTENT_SEARCH_PROVIDER)
            && interface_exists(self::CONTENT_SEARCH_LIMITER)
            && class_exists(self::CONTENT_SEARCH_REQUEST)
            && class_exists(self::CONTENT_SEARCH_FILTERS);
    }

    /** @return array{identity_max: int, global_max: int, window: int}|false */
    private function contentSearchConfiguration(): array|false
    {
        if ($this->contentSearchConfiguration !== null) {
            return $this->contentSearchConfiguration;
        }

        $api = $this->config['api'] ?? [];
        $contentSearch = is_array($api) ? ($api['content_search'] ?? []) : [];
        if (!is_array($contentSearch)) {
            throw new ConfigException('The api.content_search config value must be an array.');
        }
        if (!array_key_exists('enabled', $contentSearch)) {
            return $this->contentSearchConfiguration = false;
        }
        if (!is_bool($contentSearch['enabled'])) {
            throw new ConfigException('The api.content_search.enabled config value must be a strict boolean.');
        }

        if (!$contentSearch['enabled']) {
            return $this->contentSearchConfiguration = false;
        }

        $rateLimit = $contentSearch['rate_limit'] ?? [];
        if (!is_array($rateLimit)) {
            throw new ConfigException('The api.content_search.rate_limit config value must be an array.');
        }

        $identity = self::boundedConfigInt($rateLimit, 'identity_max', 30, 1, 10_000);
        $global = self::boundedConfigInt($rateLimit, 'global_max', 300, 1, 100_000);
        $window = self::boundedConfigInt($rateLimit, 'window_seconds', 60, 1, 3_600);
        if ($global < $identity) {
            throw new ConfigException('The api.content_search.rate_limit.global_max value must be at least identity_max.');
        }

        return $this->contentSearchConfiguration = [
            'identity_max' => $identity,
            'global_max' => $global,
            'window' => $window,
        ];
    }

    /** @param array<string, mixed> $config */
    private static function boundedConfigInt(array $config, string $key, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }
        $value = $config[$key];
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ConfigException(sprintf(
                'The api.content_search.rate_limit.%s config value must be an integer between %d and %d.',
                $key,
                $minimum,
                $maximum,
            ));
        }

        return $value;
    }

    /**
     * The deployment's exact-match CORS origin allowlist (`cors_origins`),
     * reused by the approval decision controller's origin gate. Entries are
     * compared with strict string identity only — never substring, suffix,
     * wildcard, regex, or host-only matching.
     *
     * @return list<string>
     */
    private function corsOrigins(): array
    {
        $origins = $this->config['cors_origins'] ?? [];
        if (!\is_array($origins)) {
            return [];
        }

        return array_values(array_filter($origins, static fn($origin): bool => \is_string($origin) && $origin !== ''));
    }

    /**
     * `mcp.write_tier.approval.allow_self_approval` — STRICT boolean, DEFAULT
     * **false**: separation of duties stands unless a deployment explicitly
     * states otherwise. Strict means a PHP `bool` only — deliberately narrower
     * than the sibling `mcp.write_tier.approval.*` keys' coercing allowlist,
     * because this key weakens a security control and its intent must be
     * stated, not inferred from a string or integer shape. Any non-bool value
     * throws at wiring time (fail closed) naming the key and the value's TYPE
     * only.
     *
     * @throws ConfigException when a supplied value is not a PHP bool
     */
    private function approvalAllowsSelfApproval(): bool
    {
        $mcp = $this->config['mcp'] ?? [];
        $writeTier = \is_array($mcp) && \is_array($mcp['write_tier'] ?? null) ? $mcp['write_tier'] : [];
        $approval = \is_array($writeTier['approval'] ?? null) ? $writeTier['approval'] : [];

        if (!\array_key_exists('allow_self_approval', $approval)) {
            return false;
        }

        $value = $approval['allow_self_approval'];
        if (\is_bool($value)) {
            return $value;
        }

        throw new ConfigException(sprintf(
            'The mcp.write_tier.approval.allow_self_approval config value must be a strict boolean '
            . '(PHP true or false — coerced string/integer forms are not accepted for this key); got %s.',
            get_debug_type($value),
        ));
    }
}
