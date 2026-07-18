<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;

/** Closed typed authority used only by workflow transition/persistence invariants. @api */
final class WorkflowEntitySnapshotReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $valueAuthority;

    public function __construct()
    {
        $this->valueAuthority = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(EntityInterface $entity): WorkflowEntitySnapshot
    {
        $values = $entity instanceof EntityBase
            ? ($this->valueAuthority)($entity)
            : [
                'workflow_state' => $entity->get('workflow_state'),
                'status' => $entity->get('status'),
                'published_revision_id' => $entity->get('published_revision_id'),
                'revision_id' => $entity->get('revision_id'),
            ];

        return new WorkflowEntitySnapshot(
            workflowState: is_string($values['workflow_state'] ?? null) && $values['workflow_state'] !== '' ? $values['workflow_state'] : null,
            status: EntityValues::statusToInt($values['status'] ?? 0),
            publishedRevisionId: self::identifier($values['published_revision_id'] ?? null),
            revisionId: self::identifier($values['revision_id'] ?? null),
        );
    }

    private static function identifier(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
