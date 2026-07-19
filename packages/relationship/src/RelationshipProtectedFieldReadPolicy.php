<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Exact fail-closed account policy for Protected relationship fields. @api */
final class RelationshipProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    private const array PROTECTED_FIELDS = [
        'confidence',
        'directionality',
        'end_date',
        'from_entity_id',
        'from_entity_type',
        'notes',
        'source_ref',
        'start_date',
        'status',
        'to_entity_id',
        'to_entity_type',
        'weight',
    ];

    private const array TOPOLOGY_FIELDS = [
        'from_entity_id',
        'from_entity_type',
        'to_entity_id',
        'to_entity_type',
    ];

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'relationship' || !in_array($fieldName, self::PROTECTED_FIELDS, true)) {
            return AccessResult::forbidden('Relationship field policy cannot release this field.');
        }

        $expectedSubject = array_values(array_filter(
            self::TOPOLOGY_FIELDS,
            static fn(string $field): bool => $field !== $fieldName,
        ));
        if ($subject->fields() !== $expectedSubject) {
            return AccessResult::forbidden('Relationship field release requires the exact compiled topology input.');
        }

        if ($principal->hasPermission('administer nodes') || $principal->hasPermission('access content')) {
            return AccessResult::allowed('Account may read viewable relationship fields.');
        }

        return AccessResult::forbidden('Reading relationship fields requires content-view permission.');
    }
}
