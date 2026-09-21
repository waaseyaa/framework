<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

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
            return $principal->hasPermission(NodePermissions::ADMINISTER)
                ? AccessResult::allowed('Node administrators may read protected node fields.')
                : AccessResult::forbidden('Protected node fields require node administration.');
        }

        if ($principal->hasPermission(NodePermissions::ADMINISTER)) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        $status = in_array('status', $subject->fields(), true) ? $subject->get('status') : null;
        $uid = in_array('uid', $subject->fields(), true) ? $subject->get('uid') : null;
        $isOwner = $principal->isAuthenticated() && $uid !== null && (string) $principal->id() === (string) $uid;
        $type = $structure->bundleId;

        return match ($operationOrField) {
            'view' => $status === true && $principal->hasPermission(NodePermissions::ACCESS_CONTENT)
                ? AccessResult::allowed('Published node view allowed.')
                : ($isOwner && $principal->hasPermission(NodePermissions::VIEW_OWN_UNPUBLISHED)
                    ? AccessResult::allowed('Author may view own unpublished node.')
                    : AccessResult::neutral('Node view not granted.')),
            'update' => $principal->hasPermission(NodePermissions::editAny($type))
                || ($isOwner && $principal->hasPermission(NodePermissions::editOwn($type)))
                ? AccessResult::allowed('Node update allowed.')
                : AccessResult::neutral('Node update not granted.'),
            'delete' => $principal->hasPermission(NodePermissions::deleteAny($type))
                || ($isOwner && $principal->hasPermission(NodePermissions::deleteOwn($type)))
                ? AccessResult::allowed('Node delete allowed.')
                : AccessResult::neutral('Node delete not granted.'),
            default => AccessResult::neutral('Node operation not recognized.'),
        };
    }
}
