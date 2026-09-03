<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * Whether a compiler declares that it may render a strict superset of its
 * unit's recorded path set (ADR-025 D-2.3a).
 *
 * The backing strings are the `set_evolution` values of the
 * `waaseyaa.artifact_plan` document and are therefore inside every plan digest.
 *
 * This is a declaration, not a permission. `Additive` is valid only for the
 * compilers on D-2.3a's closed eligibility list, and that list is evaluated by
 * the execution authority against the plan's declared generator — a compiler
 * cannot assert its own eligibility by setting this member.
 *
 * @api
 */
enum ArtifactSetEvolution: string
{
    /** The unit's recorded path set may not grow. The default for every compiler. */
    case Frozen = 'frozen';

    /** The unit's recorded path set may grow, subject to engine-side eligibility. */
    case Additive = 'additive';
}
