<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Audit\Approval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;

#[CoversClass(ApprovalRequestPage::class)]
final class ApprovalRequestPageTest extends TestCase
{
    #[Test]
    public function a_page_carries_its_requests_and_next_cursor(): void
    {
        $request = $this->request();

        $page = new ApprovalRequestPage([$request], 'opaque-cursor');

        self::assertSame([$request], $page->requests);
        self::assertSame('opaque-cursor', $page->nextCursor);
    }

    #[Test]
    public function a_terminal_page_may_be_empty_with_no_cursor(): void
    {
        $page = new ApprovalRequestPage([]);

        self::assertSame([], $page->requests);
        self::assertNull($page->nextCursor);
    }

    #[Test]
    public function a_page_rejects_a_non_list_requests_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequestPage(['keyed' => $this->request()]);
    }

    #[Test]
    public function a_page_rejects_elements_that_are_not_approval_requests(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequestPage([$this->request(), 'not-a-request']);
    }

    #[Test]
    public function a_page_rejects_a_blank_next_cursor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequestPage([], '');
    }

    private function request(): ApprovalRequest
    {
        return new ApprovalRequest(
            id: 'apr_0123456789abcdef0123456789abcdef',
            tuple: ApprovalTuple::forCall('token:ab12', 'mcp.write', 'node_delete', ['id' => 7]),
            status: ApprovalStatus::Pending,
            correlationId: 'corr-1',
            safeArguments: ['id' => 7],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone('UTC')),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00', new \DateTimeZone('UTC')),
        );
    }
}
