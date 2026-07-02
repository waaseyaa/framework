<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability;

use Waaseyaa\AI\Observability\Analysis\AnomalyDetector;
use Waaseyaa\AI\Observability\Cost\BudgetManager;
use Waaseyaa\AI\Observability\Cost\CostTracker;
use Waaseyaa\AI\Observability\Cost\ModelPricing;
use Waaseyaa\AI\Observability\Cost\TokenAccountant;
use Waaseyaa\AI\Observability\Listener\LlmCallListener;
use Waaseyaa\AI\Observability\Listener\ToolCallListener;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'trace',
            label: 'Trace',
            description: 'Agent execution trace',
            class: Trace::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
            group: 'ai',
        ));

        $this->singleton(TraceContext::class, fn(): TraceContext => new TraceContext());

        $overrides = $this->config['observability']['model_pricing_overrides'] ?? [];
        $this->singleton(ModelPricing::class, fn(): ModelPricing => new ModelPricing(is_array($overrides) ? $overrides : []));

        $enabled = (bool) ($this->config['observability']['enabled'] ?? true);
        $this->singleton(TraceRecorderInterface::class, function () use ($enabled): TraceRecorderInterface {
            if (!$enabled) {
                return new NullTraceRecorder();
            }

            $repo = $this->resolve(EntityTypeManager::class)->getRepository('trace');
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(TraceContext::class);

            return new TraceRecorder($repo, $database, $context);
        });

        $this->singleton(TokenAccountant::class, fn(): TokenAccountant => new TokenAccountant(
            $this->resolve(TraceRecorderInterface::class),
            $this->resolve(ModelPricing::class),
        ));

        $this->singleton(CostTracker::class, fn(): CostTracker => new CostTracker(
            $this->resolve(DatabaseInterface::class),
        ));

        $dailyLimit = (float) ($this->config['observability']['daily_limit_usd'] ?? 100.0);
        $perRequestLimit = (float) ($this->config['observability']['per_request_limit_usd'] ?? 10.0);
        $this->singleton(BudgetManager::class, fn(): BudgetManager => new BudgetManager(
            $this->resolve(CostTracker::class),
            $dailyLimit,
            $perRequestLimit,
        ));

        $this->singleton(AnomalyDetector::class, fn(): AnomalyDetector => new AnomalyDetector());
    }

    public function boot(): void
    {
        // The kernel-services bus serves the dispatcher ONLY under the
        // Symfony-contracts FQCN (ProviderRegistryKernelServices::get()).
        // This previously resolved the Symfony *Component* FQCN (never
        // served — the bus matches on exact abstract string) and then
        // instanceof-checked the concrete `Symfony\Component\EventDispatcher\
        // EventDispatcher` class, which the served `SymfonyEventDispatcherAdapter`
        // is not — so both the key and the type-check were wrong, and the
        // LLM-call/tool-call telemetry listeners never registered in a real
        // kernel boot. Resolve the served key, then type-check against the
        // foundation contract (#1852 pattern).
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $dispatcher->addSubscriber(new LlmCallListener(
            $this->resolve(TraceContext::class),
            $this->resolve(TokenAccountant::class),
        ));

        $dispatcher->addSubscriber(new ToolCallListener(
            $this->resolve(TraceContext::class),
            $this->resolve(TraceRecorderInterface::class),
        ));
    }
}
