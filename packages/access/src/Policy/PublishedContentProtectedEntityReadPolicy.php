<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\EntityValues;

/** Closed V2 published-content entity decision. @internal */
final readonly class PublishedContentProtectedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(private EntityTypeManagerInterface $entityTypeManager) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($operation !== 'view'
            || !$this->entityTypeManager->hasDefinition($structure->entityTypeId)
            || $this->entityTypeManager->getDefinition($structure->entityTypeId)->getGroup() !== PublishedContentAccessPolicy::PUBLIC_GROUP
            || !in_array('status', $subject->fields(), true)
        ) {
            return AccessResult::neutral('Default protected policy grants only published-content view.');
        }

        return EntityValues::statusToInt($subject->get('status')) === 1
            ? AccessResult::allowed('Published content is publicly viewable by default.')
            : AccessResult::neutral('Content is not published.');
    }
}
