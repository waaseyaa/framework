<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Pipeline;

use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Entity\EntityInterface;

final class EmbeddingPipeline
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly EmbeddingStorageInterface $storage,
        private readonly array $config = [],
        private readonly ?EmbeddingProviderInterface $provider = null,
    ) {}

    public function processEntity(EntityInterface $entity): void
    {
        $entityId = $entity->id();
        if ($entityId === null || $entityId === '') {
            return;
        }

        $provider = $this->provider ?? $this->resolveProvider();
        if ($provider === null) {
            error_log('[Waaseyaa] EmbeddingPipeline: no embedding provider configured; skipping.');
            return;
        }

        $text = $this->extractText($entity);
        if (trim($text) === '') {
            return;
        }

        $vector = $provider->embed($text);
        $this->storage->store(
            $entity->getEntityTypeId(),
            (string) $entityId,
            $vector,
        );
    }

    private function resolveProvider(): ?EmbeddingProviderInterface
    {
        return EmbeddingProviderFactory::fromConfig($this->config);
    }

    private function extractText(EntityInterface $entity): string
    {
        $values = $entity->toArray();
        $fields = $this->configuredFieldsForEntity($entity);
        if ($fields === []) {
            $fields = ['title', 'name', 'body'];
        }

        $parts = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            if (is_string($value) || is_int($value) || is_float($value)) {
                $string = trim((string) $value);
                if ($string !== '') {
                    $parts[] = $string;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function configuredFieldsForEntity(EntityInterface $entity): array
    {
        $entityTypeId = $entity->getEntityTypeId();
        $ai = is_array($this->config['ai'] ?? null) ? $this->config['ai'] : [];
        $map = is_array($ai['embedding_fields'] ?? null) ? $ai['embedding_fields'] : [];
        $configured = $map[$entityTypeId] ?? null;
        if (!is_array($configured)) {
            return [];
        }

        if (array_is_list($configured)) {
            return $this->normalizeFieldList($configured);
        }

        $bundle = trim($entity->bundle());
        if ($bundle !== '' && is_array($configured[$bundle] ?? null)) {
            $bundleFields = $this->normalizeFieldList($configured[$bundle]);
            if ($bundleFields !== []) {
                return $bundleFields;
            }
        }

        foreach (['*', 'default', '_default'] as $fallbackKey) {
            if (!is_array($configured[$fallbackKey] ?? null)) {
                continue;
            }

            $fallbackFields = $this->normalizeFieldList($configured[$fallbackKey]);
            if ($fallbackFields !== []) {
                return $fallbackFields;
            }
        }

        return [];
    }

    /**
     * @param array<int, mixed> $fields
     * @return list<string>
     */
    private function normalizeFieldList(array $fields): array
    {
        $output = [];
        foreach ($fields as $field) {
            if (is_string($field) && $field !== '') {
                $output[] = $field;
            }
        }

        return $output;
    }
}
