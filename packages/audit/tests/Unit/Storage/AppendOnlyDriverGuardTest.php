<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Audit\Storage\AppendOnlyDriverGuard;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;

#[CoversClass(AppendOnlyDriverGuard::class)]
final class AppendOnlyDriverGuardTest extends TestCase
{
    #[Test]
    public function it_allows_insert_with_empty_id(): void
    {
        $inner = $this->createMock(EntityStorageDriverInterface::class);
        $inner->expects($this->once())->method('write')->with('audit_event', '', [])->willReturn('1');

        $guard = new AppendOnlyDriverGuard($inner);
        $result = $guard->write('audit_event', '', []);

        $this->assertSame('1', $result);
    }

    #[Test]
    public function it_throws_on_update_for_audit_event(): void
    {
        $inner = $this->createMock(EntityStorageDriverInterface::class);
        $inner->expects($this->never())->method('write');

        $guard = new AppendOnlyDriverGuard($inner);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $guard->write('audit_event', '42', ['event_kind' => 'entity.write']);
    }

    #[Test]
    public function it_throws_on_remove_for_audit_event(): void
    {
        $inner = $this->createMock(EntityStorageDriverInterface::class);
        $inner->expects($this->never())->method('remove');

        $guard = new AppendOnlyDriverGuard($inner);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/append-only/');

        $guard->remove('audit_event', '42');
    }

    #[Test]
    public function it_allows_remove_for_non_audit_entity(): void
    {
        $inner = $this->createMock(EntityStorageDriverInterface::class);
        $inner->expects($this->once())->method('remove')->with('node', '1');

        $guard = new AppendOnlyDriverGuard($inner);
        $guard->remove('node', '1');
    }

    #[Test]
    public function it_delegates_read_to_inner_driver(): void
    {
        $inner = $this->createMock(EntityStorageDriverInterface::class);
        $inner->expects($this->once())->method('read')->willReturn(['id' => '1']);

        $guard = new AppendOnlyDriverGuard($inner);
        $result = $guard->read('audit_event', '1');

        $this->assertSame(['id' => '1'], $result);
    }
}
