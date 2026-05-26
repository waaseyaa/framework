<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AI\Observability\Analysis\AnomalyDetector;
use Waaseyaa\AI\Observability\Cost\BudgetManager;
use Waaseyaa\AI\Observability\Cost\CostTracker;
use Waaseyaa\AI\Observability\Cost\ModelPricing;
use Waaseyaa\AI\Observability\Cost\TokenAccountant;
use Waaseyaa\AI\Observability\Listener\LlmCallListener;
use Waaseyaa\AI\Observability\Listener\ToolCallListener;
use Waaseyaa\AI\Observability\ReadModel\RunDetailReadModel;
use Waaseyaa\AI\Observability\ReadModel\RunListReadModel;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
use Waaseyaa\AI\Observability\Replay\RunReplayService;
use Waaseyaa\AI\Pipeline\PipelineDispatcher;
use Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface;
use Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
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

        // M5B: AI Observability Runs read models + replay service
        $this->singleton(RunListReadModelInterface::class, fn(): RunListReadModelInterface => new RunListReadModel(
            $this->resolve(EntityTypeManager::class)->getRepository('trace'),
            $this->resolve(DatabaseInterface::class),
        ));

        $this->singleton(RunDetailReadModelInterface::class, fn(): RunDetailReadModelInterface => new RunDetailReadModel(
            $this->resolve(EntityTypeManager::class)->getRepository('trace'),
            $this->resolve(DatabaseInterface::class),
        ));

        $this->singleton(RunReplayServiceInterface::class, fn(): RunReplayServiceInterface => new RunReplayService(
            $this->resolve(EntityTypeManager::class),
            $this->resolve(PipelineDispatcher::class),
        ));
    }

    public function boot(): void
    {
        try {
            $dispatcher = $this->resolve(EventDispatcherInterface::class);
        } catch (\RuntimeException) {
            return;
        }

        if (!$dispatcher instanceof EventDispatcher) {
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
