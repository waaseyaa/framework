<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/**
 * Closed fixed-shape reader for the result of a capability-authorized content
 * operation.
 *
 * A publisher must be able to receive the fields the app deliberately placed
 * in its publishing descriptor without also receiving a broad field-read
 * permission such as `administer nodes`. The projection is fixed at
 * construction to the descriptor's status, slug, and writable fields. Callers
 * cannot select another entity field.
 *
 * @internal
 */
final class ContentMutationSnapshotReader
{
    /** @var list<string> */
    private array $fields;

    /** @var \Closure(EntityBase, list<string>): array<string, mixed> */
    private \Closure $project;

    public function __construct(private readonly ContentTypeDescriptor $descriptor)
    {
        $this->fields = array_values(array_unique([
            $descriptor->statusField,
            $descriptor->slugField,
            ...array_keys($descriptor->writableFields),
        ]));

        $this->project = \Closure::bind(
            static function (EntityBase $entity, array $fields): array {
                $values = $entity->valueContainer->rawProjection($fields);
                foreach ($values as $field => $raw) {
                    if (isset($entity->casts[$field])) {
                        $values[$field] = $entity->valueCaster()->castIn($field, $raw, $entity->casts[$field]);
                    }
                }

                return $values;
            },
            null,
            EntityBase::class,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(EntityInterface $entity): array
    {
        $values = $this->projection($entity);
        $snapshot = [
            'id' => $entity->id(),
            'uuid' => $entity->uuid(),
            'status' => (bool) (int) ($values[$this->descriptor->statusField] ?? 0),
            'revision_id' => $entity instanceof \Waaseyaa\Entity\RevisionableEntityInterface
                ? (int) $entity->revisionId()
                : null,
        ];
        foreach ($this->descriptor->writableFields as $field => $spec) {
            $snapshot[$field] = $values[$field] ?? null;
        }
        $snapshot['slug'] = (string) ($values[$this->descriptor->slugField] ?? '');

        return $snapshot;
    }

    public function field(EntityInterface $entity, string $field): mixed
    {
        if (!\in_array($field, $this->fields, true)) {
            throw new \LogicException(sprintf(
                'Field "%s" is outside this publishing descriptor.',
                $field,
            ));
        }

        return $this->projection($entity)[$field] ?? null;
    }

    /** @return array<string, mixed> */
    private function projection(EntityInterface $entity): array
    {
        if ($entity instanceof EntityBase) {
            return ($this->project)($entity, $this->fields);
        }

        $values = [];
        foreach ($this->fields as $field) {
            $values[$field] = $entity->get($field);
        }

        return $values;
    }
}
