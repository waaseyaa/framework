<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQuery;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DBALDatabase;

#[CoversClass(AuditEventQuery::class)]
final class AuditEventQuerySubjectTest extends TestCase
{
    #[Test]
    public function subject_uri_filter_returns_only_the_exact_resource_history(): void
    {
        $database = DBALDatabase::createSqlite();
        new AuditEventSchemaHandler($database)->ensureSchema();
        $writer = new AuditEventWriter($database);

        foreach (['entity:node/7', 'entity:node/70'] as $subjectUri) {
            $writer->record(new AuditEventDescriptor(
                kind: AuditEventKind::WorkflowTransition,
                accountUid: 9,
                subjectUri: $subjectUri,
                outcome: 'allowed',
                severity: 'notice',
                entityTypeId: 'node',
                attributes: ['transition' => 'publish', 'from' => 'review', 'to' => 'published'],
            ));
        }

        $query = new AuditQuery(
            subjectUri: 'entity:node/7',
            kinds: [AuditEventKind::WorkflowTransition],
        );
        $events = iterator_to_array(new AuditEventQuery($database)->findBy($query), false);

        self::assertCount(1, $events);
        self::assertSame('entity:node/7', $events[0]->getSubjectUri());
        self::assertSame(1, new AuditEventQuery($database)->count($query));
    }
}
