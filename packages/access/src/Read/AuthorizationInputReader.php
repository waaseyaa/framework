<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Read;

use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityBase;

/**
 * Generic closed reader for an entity's compiled `authorizationInput` field
 * values (#2788, FW-SITE-BLUEPRINT-01E).
 *
 * Every hand-written access policy that needs to read a Protected field
 * before deciding access (`NodeAccessPolicy` reading `uid`/`status`,
 * `WorkflowStateGuard` reading `workflow_state`) does so through its OWN
 * closed, entity-specific reader bound to `EntityBase::class`
 * ({@see \Waaseyaa\Node\NodeAuthorizationSnapshotReader},
 * {@see \Waaseyaa\Workflows\Read\WorkflowEntitySnapshotReader}) — each one
 * hardcodes the field names it expects for its one entity type.
 *
 * A generated blueprint policy has no entity-specific class to hardcode
 * field names into: it governs whatever fields the blueprint author marked
 * `authorizationInput` (the `keys.owner` relationship field, and — for a
 * workflow-bound entity — `workflow_state`). This reader generalizes the
 * same bound-closure precedent: it reaches `EntityValueContainer::
 * entityPolicySubjectView()` (itself `@internal Closed entity-policy
 * evaluation only; reachable through a bound EntityBase authority` —
 * `EntityAccessHandler`'s own `entityPolicySubjectAuthority` closure is the
 * other caller) via a closure bound to `EntityBase::class`, exactly the way
 * `EntityAccessHandler` reaches it, then flattens the resulting
 * {@see PolicySubjectViewInterface} into a plain field-name => value array.
 *
 * Calling `entityPolicySubjectView()` with no arguments resolves
 * `EntityReadLayout::authorizationInputsFor('')` — since `''` never names a
 * real field, that call falls back to the layout's FULL `authorizationInputs`
 * list, i.e. every field the entity's `#[Field(settings: ['authorizationInput'
 * => true])]` declarations mark, not just those tied to one released field.
 *
 * @api
 */
final class AuthorizationInputReader
{
    /** @var \Closure(EntityBase): PolicySubjectViewInterface */
    private readonly \Closure $subjectView;

    public function __construct()
    {
        $this->subjectView = \Closure::bind(
            static fn(EntityBase $entity): PolicySubjectViewInterface => $entity->valueContainer->entityPolicySubjectView(),
            null,
            EntityBase::class,
        );
    }

    /** @return array<string, mixed> field name => raw comparable value, for every compiled authorization input */
    public function read(EntityBase $entity): array
    {
        $view = ($this->subjectView)($entity);
        $values = [];
        foreach ($view->fields() as $field) {
            $values[$field] = $view->get($field);
        }

        return $values;
    }
}
