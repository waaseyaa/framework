<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Audit\Approval;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;

#[CoversClass(ApprovalRequest::class)]
final class ApprovalRequestTest extends TestCase
{
    private const string REQUEST_ID = 'apr_0123456789abcdef0123456789abcdef';

    #[Test]
    public function a_pending_request_is_constructible_without_a_decision(): void
    {
        $request = $this->request(ApprovalStatus::Pending);

        self::assertSame(self::REQUEST_ID, $request->id);
        self::assertSame(ApprovalStatus::Pending, $request->status);
        self::assertNull($request->decidedByUid);
        self::assertNull($request->decidedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIds(): iterable
    {
        yield 'no prefix' => ['0123456789abcdef0123456789abcdef'];
        yield 'wrong prefix' => ['rcp_0123456789abcdef0123456789abcdef'];
        yield 'too short' => ['apr_0123456789abcdef'];
        yield 'too long' => ['apr_0123456789abcdef0123456789abcdef00'];
        yield 'uppercase hex' => ['apr_0123456789ABCDEF0123456789ABCDEF'];
        yield 'non-hex' => ['apr_0123456789ghijkl0123456789ghijkl'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('malformedIds')]
    public function a_malformed_request_id_is_rejected(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: $id,
            tuple: self::tuple(),
            status: ApprovalStatus::Pending,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
        );
    }

    #[Test]
    public function an_expiry_at_or_before_the_request_time_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: ApprovalStatus::Pending,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
        );
    }

    #[Test]
    public function an_empty_correlation_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: ApprovalStatus::Pending,
            correlationId: '',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
        );
    }

    /** @return iterable<string, array{ApprovalStatus}> */
    public static function decidedStatuses(): iterable
    {
        yield 'approved' => [ApprovalStatus::Approved];
        yield 'denied' => [ApprovalStatus::Denied];
        yield 'consumed' => [ApprovalStatus::Consumed];
    }

    #[Test]
    #[DataProvider('decidedStatuses')]
    public function a_decided_status_requires_the_deciding_operator_and_time(ApprovalStatus $status): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: $status,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
        );
    }

    #[Test]
    public function a_decided_request_carries_an_optional_normalized_decision_reason(): void
    {
        $request = new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: ApprovalStatus::Denied,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
            decidedByUid: 42,
            decidedAt: new \DateTimeImmutable('2026-08-03 10:05:00'),
            decisionReason: 'Denied by the on-call operator — ticket WSY-88.',
        );

        self::assertSame('Denied by the on-call operator — ticket WSY-88.', $request->decisionReason);
    }

    #[Test]
    public function a_decision_reason_without_a_decision_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: ApprovalStatus::Pending,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
            decisionReason: 'A reason with nothing decided.',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecisionReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'untrimmed' => [' padded '];
        yield 'newline' => ["line one\nline two"];
        yield 'carriage return' => ["line one\rline two"];
        yield 'tab' => ["col one\tcol two"];
        yield 'other control character' => ["nul\x00byte"];
        yield 'delete character' => ["del\x7Fchar"];
        yield 'over 500 unicode characters' => [str_repeat('✓', 501)];
    }

    #[Test]
    #[DataProvider('invalidDecisionReasons')]
    public function an_invalid_decision_reason_is_rejected(string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: ApprovalStatus::Approved,
            correlationId: 'corr-1',
            safeArguments: [],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00'),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00'),
            decidedByUid: 42,
            decidedAt: new \DateTimeImmutable('2026-08-03 10:05:00'),
            decisionReason: $reason,
        );
    }

    #[Test]
    public function normalization_trims_and_collapses_blank_reasons_to_null(): void
    {
        self::assertNull(ApprovalRequest::normalizeDecisionReason(null));
        self::assertNull(ApprovalRequest::normalizeDecisionReason(''));
        self::assertNull(ApprovalRequest::normalizeDecisionReason("  \t\r\n  "));
        self::assertSame('Confirmed.', ApprovalRequest::normalizeDecisionReason('  Confirmed.  '));
    }

    #[Test]
    public function normalization_accepts_exactly_500_unicode_characters_and_rejects_501(): void
    {
        $boundary = str_repeat('✓', 500);

        self::assertSame($boundary, ApprovalRequest::normalizeDecisionReason($boundary));

        $this->expectException(\InvalidArgumentException::class);
        ApprovalRequest::normalizeDecisionReason($boundary . '✓');
    }

    /** @return iterable<string, array{string}> */
    public static function controlCharacterReasons(): iterable
    {
        yield 'interior newline' => ["line one\nline two"];
        yield 'interior carriage return' => ["line one\rline two"];
        yield 'interior tab' => ["col one\tcol two"];
        yield 'nul byte' => ["nul\x00byte"];
        yield 'escape' => ["esc\x1Bseq"];
        yield 'delete' => ["del\x7Fchar"];
    }

    #[Test]
    #[DataProvider('controlCharacterReasons')]
    public function normalization_rejects_control_characters_in_this_single_line_field(string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApprovalRequest::normalizeDecisionReason($reason);
    }

    #[Test]
    public function expiry_is_inclusive_at_the_boundary_with_no_sub_second_escape(): void
    {
        $request = $this->request(ApprovalStatus::Pending);
        $expiresAt = new \DateTimeImmutable('2026-08-03 10:15:00.000000');

        self::assertTrue($request->isExpiredAt($expiresAt));
        self::assertTrue($request->isExpiredAt($expiresAt->modify('+1 microsecond')));
        self::assertFalse($request->isExpiredAt($expiresAt->modify('-1 microsecond')));
    }

    #[Test]
    public function expiry_comparison_is_instant_based_not_wall_clock_text_based(): void
    {
        $request = $this->request(ApprovalStatus::Pending);

        // 10:15 UTC expressed as 12:15 in a +02:00 zone is the same instant —
        // still expired; one second before that instant is not.
        $sameInstantElsewhere = new \DateTimeImmutable('2026-08-03 12:15:00', new \DateTimeZone('+02:00'));

        self::assertTrue($request->isExpiredAt($sameInstantElsewhere));
        self::assertFalse($request->isExpiredAt($sameInstantElsewhere->modify('-1 second')));
    }

    private function request(ApprovalStatus $status): ApprovalRequest
    {
        return new ApprovalRequest(
            id: self::REQUEST_ID,
            tuple: self::tuple(),
            status: $status,
            correlationId: 'corr-1',
            safeArguments: ['title' => '[redacted]'],
            requestedAt: new \DateTimeImmutable('2026-08-03 10:00:00', new \DateTimeZone('UTC')),
            expiresAt: new \DateTimeImmutable('2026-08-03 10:15:00', new \DateTimeZone('UTC')),
        );
    }

    private static function tuple(): ApprovalTuple
    {
        return new ApprovalTuple(
            'token:ab12',
            'mcp.write',
            'node_update',
            str_repeat('a', 64),
        );
    }
}
