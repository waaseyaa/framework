<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Query;

use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Database\DatabaseInterface;

/**
 * DatabaseInterface-backed implementation of the audit query contract.
 *
 * Uses the `audit_event` table directly via the query builder for
 * performance — indexed fields (account_uid, entity_type_id/entity_uuid,
 * event_kind/created_at, created_at) allow efficient range queries.
 *
 * @api
 */
final class AuditEventQuery implements AuditQueryInterface
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * @return iterable<AuditEvent>
     */
    public function findBy(AuditQuery $query): iterable
    {
        $select = $this->database->select('audit_event', 'ae');
        $select = $select->fields('ae');
        $this->applyFilters($select, $query);
        $select = $select->orderBy('ae.created_at', 'DESC');
        $select = $select->range($query->offset, $query->limit);

        foreach ($select->execute() as $row) {
            yield new AuditEvent((array) $row);
        }
    }

    public function count(AuditQuery $query): int
    {
        $select = $this->database->select('audit_event', 'ae');
        $select = $select->fields('ae', ['id']);
        $this->applyFilters($select, $query);
        $countQuery = $select->countQuery();

        $result = $countQuery->execute();
        foreach ($result as $row) {
            $row = (array) $row;
            return (int) reset($row);
        }

        return 0;
    }

    private function applyFilters(\Waaseyaa\Database\SelectInterface $select, AuditQuery $query): void
    {
        if ($query->accountUid !== null) {
            $select = $select->condition('ae.account_uid', $query->accountUid);
        }

        if ($query->entityType !== null) {
            $select = $select->condition('ae.entity_type_id', $query->entityType);
        }

        if ($query->entityUuid !== null) {
            $select = $select->condition('ae.entity_uuid', $query->entityUuid);
        }

        if ($query->subjectUri !== null) {
            $select = $select->condition('ae.subject_uri', $query->subjectUri);
        }

        if ($query->kinds !== null && count($query->kinds) > 0) {
            $kindValues = array_map(fn($k) => $k->value, $query->kinds);
            $select = $select->condition('ae.event_kind', $kindValues, 'IN');
        }

        if ($query->from !== null) {
            $select = $select->condition('ae.created_at', $query->from->format('Y-m-d H:i:s'), '>=');
        }

        if ($query->to !== null) {
            $select = $select->condition('ae.created_at', $query->to->format('Y-m-d H:i:s'), '<=');
        }
    }
}
