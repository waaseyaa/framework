<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Support;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader;

/** Test assertion adapter over the same exact production workflow view. */
final class WorkflowSubjectView
{
    public static function state(?EntityInterface $entity): ?string
    {
        return $entity === null ? null : new WorkflowEntitySnapshotReader()->read($entity)->workflowState;
    }

    public static function status(?EntityInterface $entity): ?int
    {
        return $entity === null ? null : new WorkflowEntitySnapshotReader()->read($entity)->status;
    }

    public static function publishedRevisionId(?EntityInterface $entity): int|string|null
    {
        return $entity === null ? null : new WorkflowEntitySnapshotReader()->read($entity)->publishedRevisionId;
    }
}
