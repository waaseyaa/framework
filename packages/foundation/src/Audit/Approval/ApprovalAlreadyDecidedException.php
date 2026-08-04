<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Audit\Approval;

/**
 * A decision was attempted on a request that already carries one (#2177 F1).
 *
 * Distinct from the base {@see ApprovalStoreException} so an approval UI can
 * tell "someone else decided this moments ago" (show the recorded decision)
 * apart from "this request is unknown or expired" (refuse outright). The
 * recorded decision stands; a second decision is never an overwrite.
 *
 * @api
 */
final class ApprovalAlreadyDecidedException extends ApprovalStoreException {}
