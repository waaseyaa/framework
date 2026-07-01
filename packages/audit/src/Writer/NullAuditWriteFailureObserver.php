<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriteFailureObserver;

/**
 * No-op default observer — used when no concrete observer is bound.
 *
 * This ensures {@see AuditEventWriter} never has to null-check the observer
 * field. Operators who want metrics wire a real implementation via the
 * container ({@see \Waaseyaa\Audit\AuditServiceProvider::register()}).
 *
 * @api
 */
final class NullAuditWriteFailureObserver implements AuditWriteFailureObserver
{
    public function writeFailed(AuditEventDescriptor $descriptor, \Throwable $error): void
    {
        // Intentional no-op.
    }
}
