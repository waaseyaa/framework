<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;
use Waaseyaa\Workflows\WorkflowVisibility;

/**
 * Default-deny policy for embedding source selection and provider egress.
 *
 * Entity types and fields must be declared under `ai.vector_index`. Unknown
 * providers are conservatively treated as potentially transmitting off-host.
 */
final readonly class EmbeddingIndexPolicy
{
    /** @param array<string, array{fields: list<string>, allow_external: bool}> $rules */
    private function __construct(
        private array $rules,
        private WorkflowVisibility $workflowVisibility = new WorkflowVisibility(),
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config, ?WorkflowVisibility $workflowVisibility = null): self
    {
        $ai = is_array($config['ai'] ?? null) ? $config['ai'] : [];
        $rawRules = $ai['vector_index'] ?? [];
        if (!is_array($rawRules)) {
            throw new InvalidEmbeddingIndexPolicyException('ai.vector_index must be a map.');
        }

        $rules = [];
        foreach ($rawRules as $entityType => $rawRule) {
            if (!is_string($entityType) || trim($entityType) === '') {
                throw new InvalidEmbeddingIndexPolicyException('entity type keys must be non-empty strings.');
            }
            $entityType = trim($entityType);
            if (isset($rules[$entityType])) {
                throw new InvalidEmbeddingIndexPolicyException(sprintf('entity type "%s" is declared more than once.', $entityType));
            }
            if (!is_array($rawRule)) {
                throw new InvalidEmbeddingIndexPolicyException(sprintf('rule for "%s" must be a map.', $entityType));
            }

            $unknownKeys = array_diff(array_keys($rawRule), ['fields', 'allow_external']);
            if ($unknownKeys !== []) {
                throw new InvalidEmbeddingIndexPolicyException(sprintf(
                    'rule for "%s" contains unknown key "%s".',
                    $entityType,
                    (string) reset($unknownKeys),
                ));
            }

            $rawFields = $rawRule['fields'] ?? null;
            if (!is_array($rawFields) || !array_is_list($rawFields) || $rawFields === []) {
                throw new InvalidEmbeddingIndexPolicyException(sprintf('fields for "%s" must be a non-empty list.', $entityType));
            }
            $fields = [];
            foreach ($rawFields as $rawField) {
                if (!is_string($rawField) || trim($rawField) === '') {
                    throw new InvalidEmbeddingIndexPolicyException(sprintf('fields for "%s" must contain non-empty strings.', $entityType));
                }
                $fields[] = trim($rawField);
            }
            $fields = array_values(array_unique($fields));

            $allowExternal = $rawRule['allow_external'] ?? false;
            if (!is_bool($allowExternal)) {
                throw new InvalidEmbeddingIndexPolicyException(sprintf('allow_external for "%s" must be boolean.', $entityType));
            }

            $rules[$entityType] = [
                'fields' => $fields,
                'allow_external' => $allowExternal,
            ];
        }

        return new self($rules, $workflowVisibility ?? new WorkflowVisibility());
    }

    public function isDeclared(string $entityTypeId): bool
    {
        return isset($this->rules[$entityTypeId]);
    }

    public function embeddingText(EntityInterface $entity, ?EmbeddingProviderInterface $provider): ?string
    {
        $rule = $this->rules[$entity->getEntityTypeId()] ?? null;
        if ($rule === null) {
            return null;
        }

        if ($entity->getEntityTypeId() === 'node'
            && !$this->workflowVisibility->isEntityServedPublicForEntity($entity)) {
            return null;
        }

        $transmitsOffHost = !$provider instanceof EmbeddingProviderEgressInterface
            || $provider->transmitsOffHost();
        if ($transmitsOffHost && !$rule['allow_external']) {
            return null;
        }

        $fieldNames = array_values(array_filter(
            $rule['fields'],
            static fn(string $field): bool => $field !== 'label',
        ));
        $values = EntityValues::toCastAwareMap($entity, $fieldNames);
        $parts = [];
        foreach ($rule['fields'] as $field) {
            $value = $field === 'label' ? $entity->label() : ($values[$field] ?? null);
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                continue;
            }
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : implode("\n\n", $parts);
    }
}
