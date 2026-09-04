<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * How a generation unit's artifacts are treated after publication (ADR-025 D-2.2).
 *
 * The backing strings are the `unit.disposition` values of the
 * `waaseyaa.artifact_plan` document and are therefore inside every plan digest.
 *
 * Disposition is a property of the compiler, never a per-run caller flag; the
 * closed allowlist of compilers permitted to emit `Seeded` is enforced by the
 * execution authority, not by this value.
 *
 * @api
 */
enum GenerationUnitDisposition: string
{
    /** Re-rendered and byte-enforced on every publish that supplies the unit. */
    case Managed = 'managed';

    /** Published exactly once, then owned by the developer and never re-rendered. */
    case Seeded = 'seeded';
}
