<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Entity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Entity\AuditEvent;

/**
 * Read-model contract for the three-state actor (contract clause 4):
 * `getActorUid()` returns ?int — SQL NULL, a missing column (pre-migration
 * row), and the '' empty sentinel all read as null; `getAccountUid()` keeps
 * its legacy int semantics.
 */
#[CoversClass(AuditEvent::class)]
final class AuditEventActorTest extends TestCase
{
    #[Test]
    public function an_integer_actor_round_trips(): void
    {
        $event = new AuditEvent(['actor_uid' => 7, 'account_uid' => 7]);

        $this->assertSame(7, $event->getActorUid());
        $this->assertSame(7, $event->getAccountUid());
    }

    #[Test]
    public function a_string_integer_actor_from_the_driver_is_cast(): void
    {
        // Some drivers hydrate INTEGER columns as strings.
        $event = new AuditEvent(['actor_uid' => '42']);

        $this->assertSame(42, $event->getActorUid());
    }

    #[Test]
    public function an_anonymous_zero_actor_stays_zero_not_null(): void
    {
        $event = new AuditEvent(['actor_uid' => 0, 'account_uid' => 0]);

        $this->assertSame(0, $event->getActorUid());
    }

    #[Test]
    public function a_sql_null_actor_reads_as_null(): void
    {
        $event = new AuditEvent(['actor_uid' => null, 'account_uid' => 0]);

        $this->assertNull($event->getActorUid());
        // Legacy accessor keeps its 0-sentinel semantics for the same row.
        $this->assertSame(0, $event->getAccountUid());
    }

    #[Test]
    public function a_missing_column_reads_as_null(): void
    {
        // Pre-migration rows hydrated before the actor_uid ALTER ran.
        $event = new AuditEvent(['account_uid' => 5]);

        $this->assertNull($event->getActorUid());
        $this->assertSame(5, $event->getAccountUid());
    }

    #[Test]
    public function the_empty_string_sentinel_reads_as_null(): void
    {
        // Mirrors the getEntityTypeId2() empty-sentinel precedent.
        $event = new AuditEvent(['actor_uid' => '']);

        $this->assertNull($event->getActorUid());
    }
}
