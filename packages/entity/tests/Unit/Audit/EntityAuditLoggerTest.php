<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit\Audit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Entity\Audit\EntityAuditEntry;
use Waaseyaa\Entity\Audit\EntityAuditLogger;

#[CoversClass(EntityAuditLogger::class)]
#[CoversClass(EntityAuditEntry::class)]
final class EntityAuditLoggerTest extends TestCase
{
    private string $projectRoot;
    private EntityAuditLogger $logger;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_entity_audit_test_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->logger = new EntityAuditLogger($this->projectRoot);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    // -----------------------------------------------------------------------
    // EntityAuditEntry value object
    // -----------------------------------------------------------------------

    #[Test]
    public function entryExposesRequiredFields(): void
    {
        $entry = new EntityAuditEntry(
            actor:      'uid:42',
            action:     'create',
            entityId:   '1',
            entityType: 'note',
            tenantId:   'acme',
        );

        $this->assertSame('uid:42', $entry->actor);
        $this->assertSame('create', $entry->action);
        $this->assertSame('1', $entry->entityId);
        $this->assertSame('note', $entry->entityType);
        $this->assertSame('acme', $entry->tenantId);
        $this->assertNotEmpty($entry->timestamp);
    }

    #[Test]
    public function entryTimestampIsIso8601(): void
    {
        $entry = new EntityAuditEntry(
            actor: 'system', action: 'write', entityId: '1', entityType: 'note', tenantId: 'acme',
        );

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $entry->timestamp);
        $this->assertNotFalse($parsed, 'timestamp must be ISO 8601 / ATOM format');
    }

    #[Test]
    public function entryAcceptsOptionalReplayMetadata(): void
    {
        $entry = new EntityAuditEntry(
            actor:           'system',
            action:          'create',
            entityId:        '5',
            entityType:      'note',
            tenantId:        'acme',
            envelopeVersion: '1',
            ingestSource:    'api:import-script',
            ingestedAt:      '2026-03-07T12:00:00Z',
        );

        $this->assertSame('1', $entry->envelopeVersion);
        $this->assertSame('api:import-script', $entry->ingestSource);
        $this->assertSame('2026-03-07T12:00:00Z', $entry->ingestedAt);
    }

    #[Test]
    public function entrySerializesToArray(): void
    {
        $entry = new EntityAuditEntry(
            actor: 'uid:1', action: 'delete', entityId: '7', entityType: 'note', tenantId: 'beta',
        );

        $arr = $entry->toArray();

        $this->assertArrayHasKey('actor', $arr);
        $this->assertArrayHasKey('action', $arr);
        $this->assertArrayHasKey('entity_id', $arr);
        $this->assertArrayHasKey('entity_type', $arr);
        $this->assertArrayHasKey('tenant_id', $arr);
        $this->assertArrayHasKey('timestamp', $arr);
    }

    // -----------------------------------------------------------------------
    // EntityAuditLogger — append
    // -----------------------------------------------------------------------

    #[Test]
    public function appendWritesEntryToJSONL(): void
    {
        $entry = new EntityAuditEntry(
            actor: 'uid:1', action: 'create', entityId: '1', entityType: 'note', tenantId: 'acme',
        );

        $this->logger->append($entry);

        $entries = $this->logger->read();
        $this->assertCount(1, $entries);
        $this->assertSame('create', $entries[0]['action']);
        $this->assertSame('note', $entries[0]['entity_type']);
    }

    #[Test]
    public function appendAccumulatesMultipleEntries(): void
    {
        $this->logger->append(new EntityAuditEntry(
            actor: 'uid:1', action: 'create', entityId: '1', entityType: 'note', tenantId: 'acme',
        ));
        $this->logger->append(new EntityAuditEntry(
            actor: 'uid:2', action: 'update', entityId: '1', entityType: 'note', tenantId: 'acme',
        ));

        $this->assertCount(2, $this->logger->read());
    }

    #[Test]
    public function readReturnsEmptyWhenNoLogExists(): void
    {
        $this->assertSame([], $this->logger->read());
    }

    #[Test]
    public function readFiltersbyEntityType(): void
    {
        $this->logger->append(new EntityAuditEntry(
            actor: 'system', action: 'create', entityId: '1', entityType: 'note', tenantId: 'acme',
        ));
        $this->logger->append(new EntityAuditEntry(
            actor: 'system', action: 'create', entityId: '2', entityType: 'article', tenantId: 'acme',
        ));

        $noteEntries = $this->logger->read('note');
        $this->assertCount(1, $noteEntries);
        $this->assertSame('note', $noteEntries[0]['entity_type']);
    }

    #[Test]
    public function logPersistsAcrossInstances(): void
    {
        $this->logger->append(new EntityAuditEntry(
            actor: 'uid:1', action: 'create', entityId: '1', entityType: 'note', tenantId: 'acme',
        ));

        $another = new EntityAuditLogger($this->projectRoot);
        $this->assertCount(1, $another->read());
    }

    #[Test]
    public function replayMetadataIsStoredAndRetrieved(): void
    {
        $this->logger->append(new EntityAuditEntry(
            actor:           'system',
            action:          'create',
            entityId:        '3',
            entityType:      'note',
            tenantId:        'acme',
            envelopeVersion: '1',
            ingestSource:    'api:test',
            ingestedAt:      '2026-03-07T12:00:00Z',
        ));

        $entries = $this->logger->read();
        $this->assertSame('1', $entries[0]['envelope_version']);
        $this->assertSame('api:test', $entries[0]['ingest_source']);
    }

}
