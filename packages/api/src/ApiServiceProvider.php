<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Api\Audit\ApiAuditQueryAdapter;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\AiObservabilityRunsController;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Api\Http\Router\AiObservabilityRunsApiRouter;
use Waaseyaa\Api\Http\Router\AuditApiRouter;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Api\Http\Router\WorkflowGuardsApiRouter;
use Waaseyaa\Api\Media\ApiMediaVersionAdapter;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Access\Gate\GateInterface;
// Note: AuditQueryInterface is NOT imported at class-level — waaseyaa/audit
// is a require-dev dep. The singleton factory resolves it by string (C-002).
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Media\Version\MediaVersionRepository;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;
use Waaseyaa\Workflows\AuthoringRoleMatrix;

final class ApiServiceProvider extends ServiceProvider implements HasHttpDomainRoutersInterface
{
    public function register(): void
    {
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

    public function httpDomainRouters(HttpKernel $httpKernel): iterable
    {
        $routers = [
            new DiscoveryRouter(
                $httpKernel->getDiscoveryApiHandler(),
                $httpKernel->getEntityTypeManager(),
            ),
        ];

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
                new NotificationController($notificationDispatcher),
            );
        }

        // M4A-5 Phase 1: read-only workflow guards matrix endpoint. Same
        // indirection pattern as the queue and scheduler blocks —
        // WorkflowServiceProvider (Layer 3) is expected to bind
        // AuthoringRoleMatrix; if the binding is absent (slimmed-down
        // install) we skip wiring the router rather than crashing boot.
        // The workflow registry is currently the same closure pattern used
        // by WorkflowDefinitionsController (M4A-1) — left as the default
        // so a single change point covers all admin workflow endpoints.
        // Phase 2 (edit) follow-up: M4A-5b.
        $matrix = $this->resolveOptional(AuthoringRoleMatrix::class);
        if ($matrix instanceof AuthoringRoleMatrix) {
            $routers[] = new WorkflowGuardsApiRouter(new WorkflowGuardsController($matrix));
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

        // DIR-005 (versioned-blob-media-abstraction-01KSEFTJ): media version
        // read API. The read-model is bound in register() above; if the media
        // package is absent (slimmed-down install) the controller falls back to
        // empty/404 shapes without crashing boot. Routes are registered in
        // BuiltinRouteRegistrar (T-L), gated by _authenticated.
        $mediaVersionReadModel = $this->resolveOptional(MediaVersionReadModelInterface::class);
        $routers[] = new MediaVersionApiRouter(
            new MediaVersionController(
                $mediaVersionReadModel instanceof MediaVersionReadModelInterface ? $mediaVersionReadModel : null,
            ),
        );

        // M5B WP01: AI observability runs list + detail + replay endpoints.
        // Cross-layer: RunListReadModelInterface / RunDetailReadModelInterface /
        // RunReplayServiceInterface are declared in api (L4); adapters live in
        // ai-observability (L5) and are bound by ObservabilityServiceProvider.
        // Any missing binding → null → controller returns empty-shape responses
        // rather than crashing boot (C-003 / NFR-001).
        $runListModel = $this->resolveOptional(RunListReadModelInterface::class);
        $runDetailModel = $this->resolveOptional(RunDetailReadModelInterface::class);
        $runReplayService = $this->resolveOptional(RunReplayServiceInterface::class);
        if (
            $runListModel instanceof RunListReadModelInterface
            || $runDetailModel instanceof RunDetailReadModelInterface
            || $runReplayService instanceof RunReplayServiceInterface
        ) {
            $routers[] = new AiObservabilityRunsApiRouter(
                new AiObservabilityRunsController(
                    $runListModel instanceof RunListReadModelInterface ? $runListModel : null,
                    $runDetailModel instanceof RunDetailReadModelInterface ? $runDetailModel : null,
                    $runReplayService instanceof RunReplayServiceInterface ? $runReplayService : null,
                ),
            );
        }

        // WP05: OIDC client CRUD admin API. The oidc_client entity type is
        // registered by OidcServiceProvider (L6 oidc) which boots before api.
        // We add unconditionally — EntityTypeManager will throw clearly if
        // the entity type is missing, which is a configuration error.
        $routers[] = new OidcClientApiRouter(new OidcClientController($httpKernel->getEntityTypeManager()));

        return $routers;
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        new JsonApiRouteProvider($entityTypeManager)->registerRoutes($router);
    }
}
