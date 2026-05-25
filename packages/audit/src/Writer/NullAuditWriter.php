<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Writer;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;

/**
 * No-op audit writer for test environments and installs that opt out.
 *
 * Deployers can override the {@see AuditWriterInterface} binding in their
 * app service provider to use this class when audit logging is intentionally
 * disabled (e.g. ephemeral debugging environments). The audit log is
 * constitutional (DIR-004), so this is only for legitimate operational
 * opt-outs, not for normal production use.
 *
 * @api
 */
final class NullAuditWriter implements AuditWriterInterface
{
    public function record(AuditEventDescriptor $descriptor): void
    {
        // Intentionally silent.
    }
}
