<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Observability;

use Waaseyaa\AI\Observability\Analysis\AnomalyDetector;
use Waaseyaa\AI\Observability\Cost\BudgetManager;
use Waaseyaa\AI\Observability\Cost\CostTracker;
use Waaseyaa\AI\Observability\Recorder\NullTraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorder;
use Waaseyaa\AI\Observability\Recorder\TraceRecorderInterface;
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
            api: true,
        ));

        $this->singleton(TraceContext::class, fn(): TraceContext => new TraceContext());

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

}
