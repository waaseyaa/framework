<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasHttpDomainRoutersInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

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

        // M4B WP01: admin queue dashboard. Pull both queue services through
        // the kernel-services resolver — they're bound by QueueServiceProvider
        // (Layer 0) and routed through here in Layer 4, the same indirection
        // pattern used by AuthOidcRouteServiceProvider for auth/oidc.
        $failedJobs = $this->resolveOptional(FailedJobRepositoryInterface::class);
        $queue = $this->resolveOptional(QueueInterface::class);
        if ($failedJobs instanceof FailedJobRepositoryInterface && $queue instanceof QueueInterface) {
            $routers[] = new QueueAdminApiRouter(new QueueController($failedJobs, $queue));
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
