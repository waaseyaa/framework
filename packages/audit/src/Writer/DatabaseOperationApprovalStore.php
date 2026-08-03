<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Foundation\Audit\Approval\ApprovalAlreadyDecidedException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStoreException;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;

/**
 * Durable approval store backed by the append-only audit database (#2177 F1).
 *
 * The sibling of {@see DatabaseStrictAuditLedger}: one `mcp_approval_event`
 * row per append-only event (`requested` / `decided` / `consumed`), written
 * through {@see AppendOnlyAuditDatabase} so approval evidence can be appended
 * but never rewritten. Status is derived from the events plus the injected
 * clock ({@see ApprovalStatus}); the `UNIQUE(request_id, event_type)` index
 * makes both the decision and the consumption once-only at the storage layer.
 *
 * Expiry is fixed at `open()` time (`$ttlSeconds` from the injected clock) and
 * every expiry comparison delegates to {@see ApprovalRequest::isExpiredAt()} —
 * inclusive at the boundary, instant-based — so an expired approval cannot be
 * decided or consumed through any sub-second window.
 *
 * @api
 */
final readonly class DatabaseOperationApprovalStore implements OperationApprovalStoreInterface
{
    public const int DEFAULT_TTL_SECONDS = 900;

    /** Version tag baked into every pending-page cursor; bump on any format change. */
    private const string PENDING_CURSOR_VERSION = 'apv1';

    private DatabaseInterface $database;
    private EntityClockInterface $clock;
    private int $ttlSeconds;

    public function __construct(
        DatabaseInterface $database,
        ?EntityClockInterface $clock = null,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('The approval expiry window must be positive.');
        }

        $this->database = $database instanceof AppendOnlyAuditDatabase
            ? $database
            : new AppendOnlyAuditDatabase($database);
        $this->clock = $clock ?? new UtcEntityClock();
        $this->ttlSeconds = $ttlSeconds;
    }

    public function open(ApprovalTuple $tuple, string $correlationId, array $safeArguments): ApprovalRequest
    {
        if ($correlationId === '') {
            throw new \InvalidArgumentException('Opening an approval request requires a correlation id.');
        }

        try {
            $reusable = $this->reusablePendingRequest($tuple->requestKey);
            if ($reusable !== null) {
                return $reusable;
            }

            $now = $this->utc($this->clock->now());
            $requestId = 'apr_' . bin2hex(random_bytes(16));

            $this->database->insert('mcp_approval_event')->values($this->tupleColumns($tuple) + [
                'request_id' => $requestId,
                'event_type' => 'requested',
                'correlation_id' => $correlationId,
                'safe_arguments' => json_encode(
                    $safeArguments,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'expires_at' => $now->modify(sprintf('+%d seconds', $this->ttlSeconds))->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ])->execute();

            $request = $this->find($requestId);
            if ($request === null) {
                throw new \RuntimeException('The approval request was not readable after its durable append.');
            }

            return $request;
        } catch (ApprovalStoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApprovalStoreException(
                'The approval request could not be made durable; the operation must not proceed.',
                0,
                $e,
            );
        }
    }

    public function find(string $requestId): ?ApprovalRequest
    {
        try {
            $events = iterator_to_array($this->database->query(
                'SELECT * FROM mcp_approval_event WHERE request_id = :id ORDER BY id',
                ['id' => $requestId],
            ));

            return $events === [] ? null : $this->hydrate($events);
        } catch (ApprovalStoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApprovalStoreException('The approval store could not be read.', 0, $e);
        }
    }

    public function listPending(
        int $limit = OperationApprovalStoreInterface::PENDING_PAGE_DEFAULT_LIMIT,
        ?string $cursor = null,
    ): ApprovalRequestPage {
        if ($limit < 1 || $limit > OperationApprovalStoreInterface::PENDING_PAGE_MAX_LIMIT) {
            throw new \InvalidArgumentException(sprintf(
                'A pending-approval page limit must be between 1 and %d.',
                OperationApprovalStoreInterface::PENDING_PAGE_MAX_LIMIT,
            ));
        }

        // The cursor is validated in full — and any malformed value rejected —
        // before the store is touched.
        $afterId = $cursor === null ? 0 : $this->decodePendingCursor($cursor);

        try {
            $now = $this->utc($this->clock->now());
            $requests = [];

            while (true) {
                $remaining = $limit - \count($requests);

                // One bounded chunk of live-pending candidates strictly after
                // the scan position, oldest first. Decided and consumed
                // requests are excluded through the (request_id, event_type)
                // unique index; definitely-expired requests are excluded by
                // the sortable fixed-width UTC text comparison, which agrees
                // exactly with ApprovalRequest::isExpiredAt() (`live` means
                // `expires_at > now`, expiry is inclusive at the boundary).
                // The extra row (`remaining + 1`) only detects whether more
                // rows exist; it is never returned.
                $rows = iterator_to_array($this->database->query(sprintf(
                    "SELECT * FROM mcp_approval_event r
                     WHERE r.event_type = 'requested' AND r.id > :after AND r.expires_at > :now
                       AND NOT EXISTS (
                         SELECT 1 FROM mcp_approval_event e
                         WHERE e.request_id = r.request_id AND e.event_type IN ('decided', 'consumed')
                       )
                     ORDER BY r.id
                     LIMIT %d",
                    $remaining + 1,
                ), ['after' => $afterId, 'now' => $now->format('Y-m-d H:i:s.u')]));

                $hasMore = \count($rows) > $remaining;
                foreach (\array_slice($rows, 0, $remaining) as $row) {
                    $afterId = (int) $row['id'];
                    $request = $this->hydrate([$row]);
                    // The authoritative status derivation has the final say;
                    // the SQL prefilter can only ever agree with it.
                    if ($request->status === ApprovalStatus::Pending) {
                        $requests[] = $request;
                    }
                }

                if (\count($requests) === $limit) {
                    return new ApprovalRequestPage(
                        $requests,
                        $hasMore ? $this->encodePendingCursor($afterId) : null,
                    );
                }
                if (!$hasMore) {
                    return new ApprovalRequestPage($requests, null);
                }
                // Filtered rows left the page short with rows still ahead:
                // continue from the advanced scan position with another
                // bounded chunk. Skipped rows can never become pending again
                // (decisions are once-only and expiry is fixed at open time),
                // so advancing past them loses nothing.
            }
        } catch (ApprovalStoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ApprovalStoreException('The approval store could not be read.', 0, $e);
        }
    }

    public function decide(string $requestId, bool $approved, int $operatorUid, ?string $reason = null): ApprovalRequest
    {
        if ($operatorUid <= 0) {
            throw new \InvalidArgumentException(
                'An approval decision requires the server-derived uid of a real operator.',
            );
        }

        // An invalid reason must be rejected before any row is appended.
        $reason = ApprovalRequest::normalizeDecisionReason($reason);

        $transaction = $this->database->transaction('mcp-approval-decide');

        try {
            $request = $this->find($requestId);
            if ($request === null) {
                throw new ApprovalStoreException('The approval request does not exist.');
            }
            if ($request->decidedAt !== null) {
                throw new ApprovalAlreadyDecidedException('The approval request already carries a decision.');
            }
            if ($request->status === ApprovalStatus::Expired) {
                throw new ApprovalStoreException('The approval request has expired and can no longer be decided.');
            }

            $this->database->insert('mcp_approval_event')->values($this->tupleColumns($request->tuple) + [
                'request_id' => $requestId,
                'event_type' => 'decided',
                'correlation_id' => $request->correlationId,
                'decision' => $approved ? 'approved' : 'denied',
                'operator_uid' => $operatorUid,
                'decision_reason' => $reason,
                'created_at' => $this->utc($this->clock->now())->format('Y-m-d H:i:s.u'),
            ])->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly($transaction);

            if ($e instanceof ApprovalStoreException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }

            // A unique-index refusal means a concurrent decision landed first;
            // report it as the decision conflict it is, not as infrastructure.
            if ($this->eventExists($requestId, 'decided')) {
                throw new ApprovalAlreadyDecidedException('The approval request already carries a decision.', 0, $e);
            }

            throw new ApprovalStoreException(
                'The approval decision could not be made durable.',
                0,
                $e,
            );
        }

        $decided = $this->find($requestId);
        if ($decided === null) {
            throw new ApprovalStoreException('The approval request was not readable after its decision.');
        }

        return $decided;
    }

    public function consume(string $requestId, string $receiptId, string $retryCorrelationId): bool
    {
        if ($receiptId === '') {
            throw new \InvalidArgumentException('Consuming an approval requires the strict-ledger receipt id.');
        }
        if ($retryCorrelationId === '') {
            throw new \InvalidArgumentException('Consuming an approval requires the retry correlation id.');
        }

        $transaction = $this->database->transaction('mcp-approval-consume');

        try {
            $request = $this->find($requestId);
            $now = $this->utc($this->clock->now());

            if ($request === null || $request->status !== ApprovalStatus::Approved || $request->isExpiredAt($now)) {
                $this->rollBackQuietly($transaction);

                return false;
            }

            $this->database->insert('mcp_approval_event')->values($this->tupleColumns($request->tuple) + [
                'request_id' => $requestId,
                'event_type' => 'consumed',
                'correlation_id' => $retryCorrelationId,
                'receipt_id' => $receiptId,
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ])->execute();

            $transaction->commit();

            return true;
        } catch (\Throwable $e) {
            $this->rollBackQuietly($transaction);

            if ($e instanceof ApprovalStoreException) {
                throw $e;
            }

            // A unique-index refusal means a concurrent consumer won the race:
            // state loss, not infrastructure failure — the caller must not run.
            if ($this->eventExists($requestId, 'consumed')) {
                return false;
            }

            throw new ApprovalStoreException(
                'The approval consumption could not be made durable; the operation must not proceed.',
                0,
                $e,
            );
        }
    }

    /**
     * Encode a pending-scan position as the opaque page cursor.
     *
     * The payload is only the versioned, immutable row id of the last scanned
     * `requested` event (`apv1:<id>`), base64url-encoded without padding. It
     * carries no mutable state and grants nothing: replaying or altering it
     * can only move the read-only scan position.
     */
    private function encodePendingCursor(int $lastScannedId): string
    {
        return rtrim(strtr(
            base64_encode(self::PENDING_CURSOR_VERSION . ':' . $lastScannedId),
            '+/',
            '-_',
        ), '=');
    }

    /**
     * Strictly decode a caller-supplied pending-page cursor.
     *
     * Every structural property is enforced — base64url alphabet, decodable
     * payload, exact `apv1:<positive id, no leading zeros>` shape, and a
     * canonical re-encode round-trip — so a malformed or tampered cursor is
     * rejected as {@see \InvalidArgumentException} before any query runs.
     *
     * @return int the last scanned `requested` event row id
     */
    private function decodePendingCursor(string $cursor): int
    {
        $invalid = new \InvalidArgumentException(
            'The pending-approval cursor is malformed; restart the traversal without a cursor.',
        );

        if ($cursor === '' || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
            throw $invalid;
        }

        $decoded = base64_decode(
            strtr($cursor, '-_', '+/') . str_repeat('=', (4 - \strlen($cursor) % 4) % 4),
            true,
        );
        if ($decoded === false
            || preg_match('/^' . self::PENDING_CURSOR_VERSION . ':([1-9][0-9]{0,17})$/', $decoded, $matches) !== 1
        ) {
            throw $invalid;
        }

        $lastScannedId = (int) $matches[1];
        if ($this->encodePendingCursor($lastScannedId) !== $cursor) {
            throw $invalid;
        }

        return $lastScannedId;
    }

    /**
     * The oldest unexpired, undecided pending request for the request key, if
     * any — the reuse target for a retried identical call. Concurrent first
     * calls may still each append a `requested` event (append-only storage has
     * nothing to lock); such duplicates are harmless and converge here because
     * every later retry reuses the oldest one.
     */
    private function reusablePendingRequest(string $requestKey): ?ApprovalRequest
    {
        $candidates = iterator_to_array($this->database->query(
            'SELECT request_id FROM mcp_approval_event WHERE event_type = :type AND request_key = :key ORDER BY id',
            ['type' => 'requested', 'key' => $requestKey],
        ));

        foreach ($candidates as $candidate) {
            $request = $this->find((string) $candidate['request_id']);
            if ($request !== null && $request->status === ApprovalStatus::Pending) {
                return $request;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $events the request's rows in append order
     */
    private function hydrate(array $events): ApprovalRequest
    {
        $requested = null;
        $decided = null;
        $consumed = null;
        foreach ($events as $event) {
            match ($event['event_type']) {
                'requested' => $requested ??= $event,
                'decided' => $decided ??= $event,
                'consumed' => $consumed ??= $event,
                default => null,
            };
        }

        if ($requested === null) {
            throw new \RuntimeException('An approval event stream is missing its requested event.');
        }

        $now = $this->utc($this->clock->now());
        $expiresAt = $this->parseStoredTime((string) $requested['expires_at']);
        $decision = $decided !== null ? (string) $decided['decision'] : null;

        // The derivation mirrors ApprovalRequest::isExpiredAt() exactly:
        // inclusive boundary, instant comparison. Consumed and denied are
        // terminal regardless of expiry; approval and pendency are only
        // claimed while the window is still open.
        $expired = $now >= $expiresAt;
        $status = match (true) {
            $consumed !== null => ApprovalStatus::Consumed,
            $decision === 'denied' => ApprovalStatus::Denied,
            $decision === 'approved' => $expired ? ApprovalStatus::Expired : ApprovalStatus::Approved,
            default => $expired ? ApprovalStatus::Expired : ApprovalStatus::Pending,
        };

        /** @var array<string, mixed> $safeArguments */
        $safeArguments = json_decode((string) $requested['safe_arguments'], true, 512, JSON_THROW_ON_ERROR);

        return new ApprovalRequest(
            id: (string) $requested['request_id'],
            tuple: new ApprovalTuple(
                (string) $requested['principal_key'],
                (string) $requested['surface'],
                (string) $requested['operation'],
                (string) $requested['arguments_fingerprint'],
            ),
            status: $status,
            correlationId: (string) $requested['correlation_id'],
            safeArguments: $safeArguments,
            requestedAt: $this->parseStoredTime((string) $requested['created_at']),
            expiresAt: $expiresAt,
            decidedByUid: $decided !== null ? (int) $decided['operator_uid'] : null,
            decidedAt: $decided !== null ? $this->parseStoredTime((string) $decided['created_at']) : null,
            decisionReason: isset($decided['decision_reason']) ? (string) $decided['decision_reason'] : null,
        );
    }

    /** @return array<string, string> */
    private function tupleColumns(ApprovalTuple $tuple): array
    {
        return [
            'request_key' => $tuple->requestKey,
            'principal_key' => $tuple->principalKey,
            'surface' => $tuple->surface,
            'operation' => $tuple->operation,
            'arguments_fingerprint' => $tuple->argumentsFingerprint,
        ];
    }

    private function eventExists(string $requestId, string $eventType): bool
    {
        try {
            $rows = iterator_to_array($this->database->query(
                'SELECT id FROM mcp_approval_event WHERE request_id = :id AND event_type = :type',
                ['id' => $requestId, 'type' => $eventType],
            ));

            return $rows !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Timestamps are stored and parsed as explicit UTC so a caller-supplied
     * clock in any timezone compares on the instant, never on wall-clock text.
     */
    private function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    private function parseStoredTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function rollBackQuietly(\Waaseyaa\Database\TransactionInterface $transaction): void
    {
        try {
            $transaction->rollBack();
        } catch (\Throwable) {
            // The caller's outcome is what matters; a failed rollback must not
            // mask it.
        }
    }
}
