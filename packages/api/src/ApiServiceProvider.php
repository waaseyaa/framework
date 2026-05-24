<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Controller\WorkflowGuardsController;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Api\Http\Router\WorkflowGuardsApiRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
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
    public function register(): void {}

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

        return $routers;
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        new JsonApiRouteProvider($entityTypeManager)->registerRoutes($router);
    }

    /**
     * `ServiceProvider::resolve()` throws when an abstract is unbound; for the
     * queue, scheduler, and notification services we prefer to gracefully
     * no-op if their service providers are not present in the manifest (e.g.
     * a slimmed-down CMS install) rather than crash kernel boot.
     */
    private function resolveOptional(string $abstract): ?object
    {
        try {
            return $this->resolve($abstract);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
