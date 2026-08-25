<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\AdminBuild;

/**
 * Fail-closed refusal from the canonical Admin dist rebuild + acceptance
 * operation (#2524). Carries a stable machine code plus a bounded detail list
 * (offending paths or marker ids) so the operator sees what to fix.
 *
 * @api Thrown across the bin/admin-dist-acceptance entrypoint boundary.
 */
final class AdminDistAcceptanceException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        $message = 'Admin dist acceptance refused (' . $errorCode . ')';
        if ($details !== []) {
            $message .= ': ' . implode(', ', array_slice($details, 0, 20));
        }
        parent::__construct($message . '.');
    }
}
