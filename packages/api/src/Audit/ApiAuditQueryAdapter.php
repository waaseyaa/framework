<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Audit;

use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Entity\AuditEvent;
use Waaseyaa\Audit\Enum\AuditEventKind;

/**
 * Adapter: translates between the api-local `AuditQueryDto` / `AuditEventResource`
 * and the L0 audit contracts (`AuditQueryInterface` / `AuditEvent`).
 *
 * Lives in `packages/api` (L4). The import direction is api → audit = downward
 * = allowed per the layer rule. No L0 audit type is exposed outside this class.
 *
 * Binding registered by `ApiServiceProvider::register()` — removing that
 * binding is the FR-013 dead-code guard trigger.
 */
final class ApiAuditQueryAdapter implements AuditQueryReadModelInterface
{
    public function __construct(
        private readonly AuditQueryInterface $auditQuery,
    ) {}

    /**
     * @return iterable<AuditEventResource>
     */
    public function findBy(AuditQueryDto $dto): iterable
    {
        $query = $this->toAuditQuery($dto);

        foreach ($this->auditQuery->findBy($query) as $event) {
            yield $this->toResource($event);
        }
    }

    public function count(AuditQueryDto $dto): int
    {
        return $this->auditQuery->count($this->toAuditQuery($dto));
    }

    private function toAuditQuery(AuditQueryDto $dto): AuditQuery
    {
        $kinds = null;

        if ($dto->kinds !== null) {
            $kinds = [];
            foreach ($dto->kinds as $kindStr) {
                $case = AuditEventKind::tryFrom($kindStr);
                if ($case !== null) {
                    $kinds[] = $case;
                }
                // Unknown kind values are silently skipped (future-compatible).
            }

            if ($kinds === []) {
                // All provided kind values were unknown — return empty result.
                $kinds = null;
            }
        }

        return new AuditQuery(
            accountUid: $dto->accountUid,
            entityType: $dto->entityType,
            entityUuid: $dto->entityUuid,
            kinds: $kinds,
            from: $dto->from,
            to: $dto->to,
            limit: $dto->limit,
            offset: $dto->offset,
        );
    }

    private function toResource(AuditEvent $event): AuditEventResource
    {
        return new AuditEventResource(
            id: (int) $event->id(),
            uuid: (string) ($event->get('uuid') ?? ''),
            eventKind: $event->getEventKind(),
            accountUid: $event->getAccountUid(),
            entityType: $event->getEntityTypeId2(),
            entityUuid: $event->getEntityUuid(),
            subjectUri: $event->getSubjectUri(),
            outcome: $event->getOutcome(),
            severity: $event->getSeverity(),
            attributes: $event->getAttributes(),
            createdAt: $event->getCreatedAt(),
        );
    }
}
