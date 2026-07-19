<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityValues;

/** Closed V2 entity and field policy for Node protected inputs. @internal */
final class NodeProtectedReadPolicy implements ProtectedEntityReadPolicyInterface, ProtectedFieldReadPolicyInterface
{
    private const array PROTECTED_FIELDS = ['status', 'uid', 'workflow_state'];

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operationOrField,
    ): AccessResult {
        if ($structure->entityTypeId !== 'node') {
            return AccessResult::forbidden('Node protected policy applies only to node subjects.');
        }

        if (in_array($operationOrField, self::PROTECTED_FIELDS, true)) {
            return $principal->hasPermission('administer nodes')
                ? AccessResult::allowed('Node administrators may read protected node fields.')
                : AccessResult::forbidden('Protected node fields require node administration.');
        }

        if ($principal->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        $status = in_array('status', $subject->fields(), true) ? $subject->get('status') : null;
        $uid = in_array('uid', $subject->fields(), true) ? $subject->get('uid') : null;
        $isOwner = $principal->isAuthenticated() && $uid !== null && (string) $principal->id() === (string) $uid;
        $type = $structure->bundleId;

        return match ($operationOrField) {
            'view' => EntityValues::statusToInt($status) === 1 && $principal->hasPermission('access content')
                ? AccessResult::allowed('Published node view allowed.')
                : ($isOwner && $principal->hasPermission('view own unpublished content')
                    ? AccessResult::allowed('Author may view own unpublished node.')
                    : AccessResult::neutral('Node view not granted.')),
            'update' => $principal->hasPermission("edit any $type content")
                || ($isOwner && $principal->hasPermission("edit own $type content"))
                ? AccessResult::allowed('Node update allowed.')
                : AccessResult::neutral('Node update not granted.'),
            'delete' => $principal->hasPermission("delete any $type content")
                || ($isOwner && $principal->hasPermission("delete own $type content"))
                ? AccessResult::allowed('Node delete allowed.')
                : AccessResult::neutral('Node delete not granted.'),
            default => AccessResult::neutral('Node operation not recognized.'),
        };
    }
}
