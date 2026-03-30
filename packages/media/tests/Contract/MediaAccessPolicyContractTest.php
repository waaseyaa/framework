<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaAccessPolicy;

/**
 * MediaAccessPolicy casts the entity to Media in access() for view/edit/delete.
 * We provide a real Media instance to satisfy the assertion.
 */
final class MediaAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new MediaAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'media';
    }

    protected function createEntityStub(): EntityInterface
    {
        return new Media([
            'mid' => 1,
            'uuid' => 'media-uuid-001',
            'name' => 'Test Media',
            'bundle' => 'image',
            'status' => 1,
            'uid' => 10,
        ]);
    }
}
