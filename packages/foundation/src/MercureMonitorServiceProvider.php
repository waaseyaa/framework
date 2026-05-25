<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation;

use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Http\Inbound\ChannelInspector;
use Waaseyaa\Foundation\Http\Inbound\EventStreamReadModel;
use Waaseyaa\Foundation\Http\Inbound\SubscriberObserver;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Binds the three Mercure monitor read-model interfaces to their
 * foundation adapters (M5D WP01 — T003).
 *
 * Respects `broadcasting.monitor.enabled` config flag (default true).
 * When disabled, all three bindings are skipped — `ApiServiceProvider`'s
 * `resolveOptional` calls return null and the router is not wired.
 *
 * The subscribers.json path defaults to `<storage>/broadcast/subscribers.json`
 * and is configurable via `broadcasting.monitor.subscribers_path`.
 *
 * This is the single thing that prevents the dead-code-in-production failure
 * (FR-008 kernel-boot guard test). Removing any binding here causes the
 * integration test to fail.
 */
final class MercureMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $enabled = $this->config['broadcasting']['monitor']['enabled'] ?? true;
        if ($enabled === false) {
            return;
        }

        $defaultSubscribersPath = ($this->config['storage_path'] ?? './storage')
            . '/broadcast/subscribers.json';
        $subscribersPath = $this->config['broadcasting']['monitor']['subscribers_path']
            ?? $defaultSubscribersPath;

        // The database dep is resolved lazily via a closure that captures `$this`
        // (the SP instance). This SP's own bindings must include DatabaseInterface —
        // the kernel wires this by having the container or a parent SP bind it
        // before MercureMonitorServiceProvider::register() is called.
        // For standalone testing, use a wrapper SP that pre-binds DatabaseInterface
        // via singleton() and transfers getBindings() to the wrapper, OR boot
        // the full kernel (which automatically resolves all transitive deps).
        $sp = $this;
        $this->singleton(
            ChannelInspectorInterface::class,
            static function () use ($sp): ChannelInspector {
                /** @var DatabaseInterface $db */
                $db = $sp->resolve(DatabaseInterface::class);
                return new ChannelInspector($db);
            },
        );

        $this->singleton(
            EventStreamReadModelInterface::class,
            static function () use ($sp): EventStreamReadModel {
                /** @var DatabaseInterface $db */
                $db = $sp->resolve(DatabaseInterface::class);
                return new EventStreamReadModel($db);
            },
        );

        $this->singleton(
            SubscriberObserverInterface::class,
            fn() => new SubscriberObserver($subscribersPath),
        );
    }
}
