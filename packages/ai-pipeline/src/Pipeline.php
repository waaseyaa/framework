<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Pipeline;

use Waaseyaa\Entity\ConfigEntityBase;

/**
 * Config entity representing a processing pipeline.
 *
 * A pipeline defines a sequence of processing steps. Each step is a plugin
 * that transforms data. Steps are executed in weight order, with each step's
 * output becoming the next step's input.
 */
final class Pipeline extends ConfigEntityBase
{
    /**
     * @var array<int, PipelineStepConfig>
     */
    private array $steps = [];

    /**
     * A description of the pipeline.
     */
    protected string $description = '';

    /**
     * @param array<string, mixed> $values Initial entity values.
     */
    public function __construct(array $values = [])
    {
        if (\array_key_exists('description', $values)) {
            $this->description = (string) $values['description'];
        }

        if (isset($values['steps']) && \is_array($values['steps'])) {
            foreach ($values['steps'] as $stepData) {
                if ($stepData instanceof PipelineStepConfig) {
                    $this->steps[] = $stepData;
                } elseif (\is_array($stepData)) {
                    $this->steps[] = new PipelineStepConfig(
                        id: (string) ($stepData['id'] ?? ''),
                        pluginId: (string) ($stepData['plugin_id'] ?? ''),
                        label: (string) ($stepData['label'] ?? ''),
                        weight: (int) ($stepData['weight'] ?? 0),
                        configuration: (array) ($stepData['configuration'] ?? []),
                    );
                }
            }
        }

        $this->syncStepsToValues();

        parent::__construct(
            values: $values,
            entityTypeId: 'pipeline',
            entityKeys: ['id' => 'id', 'label' => 'label'],
        );
    }

    /**
     * Get the pipeline steps, sorted by weight.
     *
     * @return array<int, PipelineStepConfig>
     */
    public function getSteps(): array
    {
        $steps = $this->steps;
        usort($steps, static fn(PipelineStepConfig $a, PipelineStepConfig $b): int => $a->weight <=> $b->weight);

        return $steps;
    }

    /**
     * Add a step to the pipeline.
     */
    public function addStep(PipelineStepConfig $step): static
    {
        $this->steps[] = $step;
        $this->syncStepsToValues();

        return $this;
    }

    /**
     * Remove a step from the pipeline by its ID.
     */
    public function removeStep(string $stepId): static
    {
        $this->steps = array_values(array_filter(
            $this->steps,
            static fn(PipelineStepConfig $step): bool => $step->id !== $stepId,
        ));
        $this->syncStepsToValues();

        return $this;
    }

    /**
     * Get the pipeline description.
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set the pipeline description.
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;
        $this->values['description'] = $description;

        return $this;
    }

    /**
     * Returns an array suitable for config export.
     */
    public function toConfig(): array
    {
        $config = parent::toConfig();
        $config['description'] = $this->description;
        $config['steps'] = array_map(
            static fn(PipelineStepConfig $step): array => [
                'id' => $step->id,
                'plugin_id' => $step->pluginId,
                'label' => $step->label,
                'weight' => $step->weight,
                'configuration' => $step->configuration,
            ],
            $this->getSteps(),
        );

        return $config;
    }

    /**
     * Sync steps to the values array to avoid dual-state bugs.
     */
    private function syncStepsToValues(): void
    {
        $this->values['steps'] = array_map(
            static fn(PipelineStepConfig $step): array => [
                'id' => $step->id,
                'plugin_id' => $step->pluginId,
                'label' => $step->label,
                'weight' => $step->weight,
                'configuration' => $step->configuration,
            ],
            $this->steps,
        );
    }
}
