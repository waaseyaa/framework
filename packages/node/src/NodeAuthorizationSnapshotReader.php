<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityValues;

/** Closed reader for Node access-policy inputs. @internal */
final class NodeAuthorizationSnapshotReader
{
    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $values;

    public function __construct()
    {
        $this->values = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
    }

    public function read(Node $node): NodeAuthorizationSnapshot
    {
        $values = ($this->values)($node);
        $uid = $values['uid'] ?? null;

        return new NodeAuthorizationSnapshot(
            type: $node->bundle(),
            authorId: is_int($uid) || is_string($uid) ? $uid : null,
            published: EntityValues::statusToInt($values['status'] ?? 0) === 1,
        );
    }
}
