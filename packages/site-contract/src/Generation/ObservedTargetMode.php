<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * The permission bits evaluation observed at one target path (ADR-025 D-6.2).
 *
 * The backing strings are the `targets[].mode` values of the
 * `waaseyaa.project_state` document, matching the zero-padded octal encoding
 * the ownership document already uses for a generated artifact's mode.
 *
 * `Unknown` is what a host that does not enforce permission bits records. The
 * decision to emit it belongs to the observing execution authority; this value
 * carries it without naming that host.
 *
 * @api
 */
enum ObservedTargetMode: string
{
    case Mode0644 = '0644';
    case Mode0755 = '0755';
    case Other = 'other';
    case Unknown = 'unknown';
}
