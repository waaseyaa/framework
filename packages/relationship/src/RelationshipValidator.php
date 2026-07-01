<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityTypeManagerInterface;

final class RelationshipValidator
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * @param array<string, mixed> $values
     */
    public function assertValid(array $values): void
    {
        $errors = $this->validate($this->normalize($values));
        if ($errors === []) {
            return;
        }

        throw new \InvalidArgumentException('Relationship validation failed: ' . implode('; ', $errors));
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public function validate(array $values): array
    {
        $errors = [];

        $required = [
            'relationship_type',
            'from_entity_type',
            'from_entity_id',
            'to_entity_type',
            'to_entity_id',
            'directionality',
            'status',
        ];

        foreach ($required as $field) {
            if (!$this->hasMeaningfulValue($values[$field] ?? null)) {
                $errors[] = sprintf('Field "%s" is required.', $field);
            }
        }

        $directionality = $values['directionality'] ?? null;
        if ($this->hasMeaningfulValue($directionality)) {
            $allowedDirectionality = ['directed', 'bidirectional'];
            if (!is_string($directionality) || !in_array($directionality, $allowedDirectionality, true)) {
                $errors[] = 'Field "directionality" must be one of: directed, bidirectional.';
            }
        }

        $relationshipType = (string) ($values['relationship_type'] ?? '');
        if ($relationshipType !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $relationshipType)) {
            $errors[] = 'Field "relationship_type" must match ^[a-z][a-z0-9_]*$.';
        }

        $status = $values['status'] ?? null;
        if ($this->hasMeaningfulValue($status) && !$this->isValidStatus($status)) {
            $errors[] = 'Field "status" must be a boolean-like value (0/1/true/false).';
        }

        if (array_key_exists('confidence', $values) && $values['confidence'] !== null && $values['confidence'] !== '') {
            if (!is_numeric($values['confidence'])) {
                $errors[] = 'Field "confidence" must be numeric in [0, 1].';
            } else {
                $confidence = (float) $values['confidence'];
                if ($confidence < 0.0 || $confidence > 1.0) {
                    $errors[] = 'Field "confidence" must be numeric in [0, 1].';
                }
            }
        }

        $startDate = $this->normalizeTemporal($values['start_date'] ?? null);
        $endDate = $this->normalizeTemporal($values['end_date'] ?? null);

        if (($values['start_date'] ?? null) !== null && $startDate === null) {
            $errors[] = 'Field "start_date" must be a unix timestamp or parseable date string.';
        }
        if (($values['end_date'] ?? null) !== null && $endDate === null) {
            $errors[] = 'Field "end_date" must be a unix timestamp or parseable date string.';
        }
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            $errors[] = 'Field "start_date" must be less than or equal to "end_date".';
        }

        $errors = array_merge($errors, $this->validateEndpoint('from_entity_type', 'from_entity_id', $values));
        $errors = array_merge($errors, $this->validateEndpoint('to_entity_type', 'to_entity_id', $values));

        return $errors;
    }

    /**
     * Normalize persisted relationship values for deterministic storage/query behavior.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function normalize(array $values): array
    {
        $normalized = $values;

        foreach (['relationship_type', 'from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'directionality', 'source_ref'] as $key) {
            if (!array_key_exists($key, $normalized)) {
                continue;
            }
            if (is_string($normalized[$key])) {
                $normalized[$key] = trim($normalized[$key]);
            }
        }

        if (array_key_exists('status', $normalized)) {
            $normalized['status'] = $this->normalizeStatus($normalized['status']);
        }

        if (array_key_exists('weight', $normalized) && $normalized['weight'] !== null && $normalized['weight'] !== '') {
            $normalized['weight'] = (float) $normalized['weight'];
        }

        if (array_key_exists('confidence', $normalized) && $normalized['confidence'] !== null && $normalized['confidence'] !== '') {
            $normalized['confidence'] = (float) $normalized['confidence'];
        }

        if (array_key_exists('start_date', $normalized)) {
            $normalized['start_date'] = $this->normalizeTemporal($normalized['start_date']);
        }
        if (array_key_exists('end_date', $normalized)) {
            $normalized['end_date'] = $this->normalizeTemporal($normalized['end_date']);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function validateEndpoint(string $typeField, string $idField, array $values): array
    {
        $errors = [];
        $entityType = $values[$typeField] ?? null;
        $entityId = $values[$idField] ?? null;

        if (!$this->hasMeaningfulValue($entityType) || !$this->hasMeaningfulValue($entityId) || !is_string($entityType)) {
            return $errors;
        }

        if (!$this->entityTypeManager->hasDefinition($entityType)) {
            $errors[] = sprintf('Field "%s" references unknown entity type "%s".', $typeField, $entityType);
            return $errors;
        }

        $storage = $this->entityTypeManager->getStorage($entityType);
        $loadId = is_string($entityId) && ctype_digit($entityId) ? (int) $entityId : $entityId;
        if ($storage->load($loadId) === null && !$this->entityExistsByUuid($entityType, (string) $entityId)) {
            $errors[] = sprintf(
                'Field "%s" references missing entity "%s:%s".',
                $idField,
                $entityType,
                (string) $entityId,
            );
        }

        return $errors;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    private function isValidStatus(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return $value === 0 || $value === 1;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['0', '1', 'true', 'false'], true);
    }

    private function normalizeStatus(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value === 0 ? 0 : 1;
        }
        if (is_float($value)) {
            return (int) ($value === 0.0 ? 0 : 1);
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'false' || $normalized === '0') {
                return 0;
            }
            if ($normalized === 'true' || $normalized === '1') {
                return 1;
            }
        }

        return $value;
    }

    private function normalizeTemporal(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (ctype_digit($trimmed)) {
                return (int) $trimmed;
            }

            $timestamp = strtotime($trimmed);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    private function entityExistsByUuid(string $entityType, string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        $definition = $this->entityTypeManager->getDefinition($entityType);
        $keys = $definition->getKeys();
        $uuidKey = $keys['uuid'] ?? null;
        if (!is_string($uuidKey) || $uuidKey === '') {
            return false;
        }

        // C-22 WP2: the query builder now lives on the repository.
        $ids = $this->entityTypeManager->getRepository($entityType)->getQuery()
            ->condition($uuidKey, $candidate)
            // system context: referential-integrity check spans access boundaries
            ->accessCheck(false)
            ->range(0, 1)
            ->execute();

        return $ids !== [];
    }
}
