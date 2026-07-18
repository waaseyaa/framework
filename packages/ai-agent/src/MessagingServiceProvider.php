<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent;

use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Waaseyaa\AI\Agent\Account\InitiatorAccountLoaderInterface;
use Waaseyaa\AI\Agent\Account\StubInitiatorAccountLoader;
use Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterInterface;
use Waaseyaa\AI\Agent\Message\RunAgent;
use Waaseyaa\AI\Agent\Message\RunAgentHandler;
use Waaseyaa\AI\Agent\Provider\NullLlmProvider;
use Waaseyaa\AI\Agent\Provider\ProviderInterface;
use Waaseyaa\AI\Agent\Reaper\StalledRunReaper;
use Waaseyaa\AI\Agent\Repository\AgentAuditLogRepository;
use Waaseyaa\AI\Agent\Repository\AgentRunRepository;
use Waaseyaa\AI\Agent\Security\AgentRunWorkerReaderInterface;
use Waaseyaa\AI\Agent\Service\AgentRunService;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Wire the production async surface for the agent executor.
 *
 * Owns:
 *
 *  - {@see RunAgentHandler} — the {@see \Symfony\Component\Messenger\Attribute\AsMessageHandler}
 *    handler that drives each queued run.
 *  - {@see AgentRunService} — the application-facing facade
 *    (`enqueue()` + `runInline()`).
 *  - {@see StalledRunReaper} — recovers worker-crashed rows.
 *  - {@see AgentRunBroadcasterInterface} — bound by
 *    {@see \Waaseyaa\AI\Agent\Broadcast\AgentRunBroadcasterServiceProvider}
 *    (registered after this provider so its binding wins).
 *  - {@see InitiatorAccountLoaderInterface} — wired to
 *    {@see StubInitiatorAccountLoader}. Apps that need real users
 *    rebind it in their own provider.
 *  - {@see ProviderInterface} — defaults to {@see NullLlmProvider} so
 *    the kernel can boot without API keys. Apps rebind to a real
 *    provider via their own provider override.
 *  - {@see MessageBusInterface} — bound to a sync {@see MessageBus}
 *    with {@see HandleMessageMiddleware} resolving {@see RunAgent} to
 *    {@see RunAgentHandler}. Apps with a production Messenger transport
 *    SHOULD rebind {@see MessageBusInterface} themselves; this binding
 *    is a fail-safe so test kernels and CLI smoke runs work out of the
 *    box without external messenger config.
 *
 * Companion to {@see AiAgentEntityServiceProvider} (entity types +
 * repositories) and {@see AiAgentServiceProvider} (executor + tool
 * registry).
 *
 * @api
 */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AgentRunBroadcasterInterface is bound by AgentRunBroadcasterServiceProvider,
        // which is registered after this provider so its singleton wins.

        $this->singleton(
            InitiatorAccountLoaderInterface::class,
            static fn(): InitiatorAccountLoaderInterface => new StubInitiatorAccountLoader(),
        );

        // Default to NullLlmProvider so the kernel boots without
        // credentials. Apps that need a real provider rebind
        // ProviderInterface in their own service provider; the kernel
        // resolves the last binding, so app-level overrides win.
        $this->singleton(
            ProviderInterface::class,
            static fn(): ProviderInterface => new NullLlmProvider(),
        );

        $this->singleton(
            RunAgentHandler::class,
            fn(): RunAgentHandler => new RunAgentHandler(
                runRepository: $this->resolve(AgentRunRepository::class),
                executor: $this->resolve(AgentExecutor::class),
                definitionRegistry: $this->resolve(AgentDefinitionRegistry::class),
                toolRegistry: $this->resolve(ToolRegistryInterface::class),
                broadcaster: $this->resolve(AgentRunBroadcasterInterface::class),
                provider: $this->resolve(ProviderInterface::class),
                accountLoader: $this->resolve(InitiatorAccountLoaderInterface::class),
                workerReader: $this->resolve(AgentRunWorkerReaderInterface::class),
                logger: $this->safeResolve(LoggerInterface::class) ?? new NullLogger(),
                eventDispatcher: ($dispatcher = $this->safeResolve(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)) instanceof EventDispatcherInterface
                    ? $dispatcher
                    : null,
            ),
        );

        $this->singleton(
            MessageBusInterface::class,
            fn(): MessageBusInterface => $this->buildSyncBus(),
        );

        $this->singleton(
            AgentRunService::class,
            fn(): AgentRunService => new AgentRunService(
                messageBus: $this->resolve(MessageBusInterface::class),
                runRepository: $this->resolve(AgentRunRepository::class),
                inlineHandler: $this->resolve(RunAgentHandler::class),
            ),
        );

        $this->singleton(
            StalledRunReaper::class,
            fn(): StalledRunReaper => new StalledRunReaper(
                runRepository: $this->resolve(AgentRunRepository::class),
                auditRepository: $this->resolve(AgentAuditLogRepository::class),
                broadcaster: $this->resolve(AgentRunBroadcasterInterface::class),
                logger: $this->safeResolve(LoggerInterface::class) ?? new NullLogger(),
            ),
        );
    }

    /**
     * Warn when the effective {@see ProviderInterface} is still the placeholder
     * {@see NullLlmProvider} after every provider has registered (DX #1608).
     *
     * Runs after all `register()` passes, so {@see $kernelServices} sees the
     * binding from any app provider that rebound {@see ProviderInterface} to a
     * real provider — in that case the resolved instance is not a
     * {@see NullLlmProvider} and the warning stays silent. It fires only when no
     * LLM is configured, surfacing why agent runs return the canned placeholder
     * response instead of failing loudly.
     */
    public function boot(): void
    {
        $provider = $this->kernelServices?->get(ProviderInterface::class)
            ?? $this->safeResolve(ProviderInterface::class);

        if ($provider instanceof NullLlmProvider) {
            $this->resolveLogger()->warning(
                'No LLM provider configured; agent runs use NullLlmProvider and '
                . 'return a placeholder response. Bind '
                . ProviderInterface::class
                . ' to a real provider in an app service provider to enable AI features.',
            );
        }
    }

    /**
     * Build a synchronous {@see MessageBus} that dispatches
     * {@see RunAgent} directly to the resolved {@see RunAgentHandler}.
     *
     * Apps that need a real Messenger transport (Doctrine, AMQP, Redis,
     * etc.) rebind {@see MessageBusInterface} in their own service
     * provider; the kernel resolves the last binding so app-level wiring
     * wins. The sync default keeps test kernels, the CLI, and smoke
     * runs working without external configuration.
     */
    private function buildSyncBus(): MessageBusInterface
    {
        $handler = $this->resolve(RunAgentHandler::class);

        $locator = new HandlersLocator([
            RunAgent::class => [
                new HandlerDescriptor($handler),
            ],
        ]);

        return new MessageBus([
            new HandleMessageMiddleware($locator),
        ]);
    }

    private function safeResolve(string $abstract): mixed
    {
        try {
            return $this->resolve($abstract);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLogger(): LoggerInterface
    {
        $candidate = $this->kernelServices?->get(LoggerInterface::class);

        return $candidate instanceof LoggerInterface ? $candidate : new NullLogger();
    }
}
