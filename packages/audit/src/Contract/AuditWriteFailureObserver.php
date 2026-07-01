<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

/**
 * L1 observer seam for audit write failures.
 *
 * Implemented by higher-layer metric collectors (e.g. Telescope / Prometheus
 * at L6) that want a loud signal when an audit INSERT fails. The seam itself
 * is L1-pure: it carries no upward imports and places no constraint on what
 * the observer does — logging, incrementing a counter, firing an alert — as
 * long as it never throws (the writer will NOT catch exceptions from the
 * observer).
 *
 * The default implementation is {@see \Waaseyaa\Audit\Writer\NullAuditWriteFailureObserver}.
 * Real wiring (e.g. a Prometheus counter) is a downstream L6 concern and out
 * of scope for this package.
 *
 * Design: §10.4 of craftsmanship/AUDIT-TAMPER-EVIDENCE-DESIGN.md.
 *
 * @api
 */
interface AuditWriteFailureObserver
{
    /**
     * Called when an {@see \Waaseyaa\Audit\Writer\AuditEventWriter} INSERT fails.
     *
     * The writer has already logged a warning. This seam is for operators who
     * need a metric / alert beyond the log line — e.g. a Prometheus counter
     * increment or a structured event to an observability backend.
     *
     * MUST NOT throw. The writer does not guard against observer exceptions.
     */
    public function writeFailed(AuditEventDescriptor $descriptor, \Throwable $error): void;
}
