<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeLifecycleManager;

#[CoversClass(EntityTypeLifecycleManager::class)]
final class EntityTypeLifecycleManagerTest extends TestCase
{
    private string $projectRoot;
    private EntityTypeLifecycleManager $manager;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_lifecycle_test_' . uniqid();
        mkdir($this->projectRoot . '/storage/framework', 0755, true);
        $this->manager = new EntityTypeLifecycleManager($this->projectRoot);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function noTypesDisabledByDefault(): void
    {
        $this->assertSame([], $this->manager->getDisabledTypeIds());
    }

    #[Test]
    public function disableAddsTypeToDisabledList(): void
    {
        $this->manager->disable('note', 1);

        $this->assertContains('note', $this->manager->getDisabledTypeIds());
    }

    #[Test]
    public function disableIsDeduplicated(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->disable('note', 1);

        $this->assertCount(1, $this->manager->getDisabledTypeIds());
    }

    #[Test]
    public function enableRemovesTypeFromDisabledList(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->enable('note', 2);

        $this->assertNotContains('note', $this->manager->getDisabledTypeIds());
    }

    #[Test]
    public function enableOnNonDisabledTypeIsNoOp(): void
    {
        $this->manager->enable('note', 1);

        $this->assertSame([], $this->manager->getDisabledTypeIds());
    }

    #[Test]
    public function multipleTypesCanBeDisabled(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->disable('article', 1);

        $disabled = $this->manager->getDisabledTypeIds();
        $this->assertContains('note', $disabled);
        $this->assertContains('article', $disabled);
    }

    #[Test]
    public function enablingOneTypeDoesNotAffectOthers(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->disable('article', 1);
        $this->manager->enable('note', 2);

        $disabled = $this->manager->getDisabledTypeIds();
        $this->assertNotContains('note', $disabled);
        $this->assertContains('article', $disabled);
    }

    #[Test]
    public function statusPersistsAcrossInstances(): void
    {
        $this->manager->disable('note', 1);

        $another = new EntityTypeLifecycleManager($this->projectRoot);
        $this->assertContains('note', $another->getDisabledTypeIds());
    }

    // -----------------------------------------------------------------------
    // Audit log
    // -----------------------------------------------------------------------

    #[Test]
    public function disableWritesAuditEntry(): void
    {
        $this->manager->disable('note', 42);

        $entries = $this->manager->readAuditLog();
        $this->assertCount(1, $entries);
        $this->assertSame('note', $entries[0]['entity_type_id']);
        $this->assertSame('disabled', $entries[0]['action']);
        $this->assertSame('42', $entries[0]['actor_id']);
        $this->assertArrayHasKey('timestamp', $entries[0]);
    }

    #[Test]
    public function enableWritesAuditEntry(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->enable('note', 99);

        $entries = $this->manager->readAuditLog();
        $this->assertCount(2, $entries);
        $this->assertSame('enabled', $entries[1]['action']);
        $this->assertSame('99', $entries[1]['actor_id']);
    }

    #[Test]
    public function readAuditLogFiltersbyEntityType(): void
    {
        $this->manager->disable('note', 1);
        $this->manager->disable('article', 1);

        $noteEntries = $this->manager->readAuditLog('note');
        $this->assertCount(1, $noteEntries);
        $this->assertSame('note', $noteEntries[0]['entity_type_id']);
    }

    #[Test]
    public function readAuditLogReturnsEmptyWhenNoLogExists(): void
    {
        $this->assertSame([], $this->manager->readAuditLog());
    }

    #[Test]
    public function auditLogContainsIsotimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $this->manager->disable('note', 1);
        $after = new \DateTimeImmutable();

        $entries = $this->manager->readAuditLog();
        $entryTime = new \DateTimeImmutable($entries[0]['timestamp']);

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $entryTime->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp() + 1, $entryTime->getTimestamp());
    }

    #[Test]
    public function isDisabledReturnsTrueForDisabledType(): void
    {
        $this->manager->disable('note', 1);

        $this->assertTrue($this->manager->isDisabled('note'));
    }

    #[Test]
    public function isDisabledReturnsFalseForEnabledType(): void
    {
        $this->assertFalse($this->manager->isDisabled('note'));
    }
}
