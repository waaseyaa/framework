<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * One bounded page of pending approval requests (#2177 F1 C1a).
 *
 * Returned by {@see OperationApprovalStoreInterface::listPending()}. The page
 * is a plain read model: at most the requested limit of live
 * {@see ApprovalStatus::Pending} requests in stable ascending requested order,
 * plus the opaque cursor that resumes the traversal — null when the store had
 * no further rows to scan when the page was assembled.
 *
 * Like {@see ApprovalRequest}, every field is safe to expose to an operator
 * UI: the requests carry the tool's redacted `safeArguments` projection only,
 * never raw call arguments.
 *
 * @api
 */
final readonly class ApprovalRequestPage
{
    /** @var list<ApprovalRequest> the page's requests, ascending requested order */
    public array $requests;

    /**
     * @param array<mixed> $requests   the page's requests, ascending requested order
     * @param ?string      $nextCursor opaque resume cursor; null on a terminal page
     */
    public function __construct(
        array $requests,
        public ?string $nextCursor = null,
    ) {
        if (!array_is_list($requests)) {
            throw new \InvalidArgumentException('An approval request page holds a list of requests.');
        }
        $validated = [];
        foreach ($requests as $request) {
            if (!$request instanceof ApprovalRequest) {
                throw new \InvalidArgumentException('An approval request page holds ApprovalRequest instances only.');
            }
            $validated[] = $request;
        }
        if ($nextCursor === '') {
            throw new \InvalidArgumentException('An approval page cursor is either opaque and non-blank, or null.');
        }

        $this->requests = $validated;
    }
}
