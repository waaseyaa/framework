<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class MutationTokenFixture
{
    public static function for(
        EntityRepositoryInterface $repository,
        string $entityTypeId,
        string $entityId,
    ): string {
        $entity = $repository->find($entityId);
        if ($entity instanceof EntityBase && $entity->mutationToken() !== null
            && $entity->getEntityTypeId() === $entityTypeId
        ) {
            return $entity->mutationToken()->toOpaqueString();
        }

        return EntityMutationToken::issue(
            'ai-tool-test',
            'default',
            $entityTypeId,
            $entityId,
            1,
        )->toOpaqueString();
    }
}
