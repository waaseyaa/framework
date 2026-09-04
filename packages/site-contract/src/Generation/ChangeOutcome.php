<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

/**
 * The governed-change protocol's terminal outcome vocabulary (ADR-025 D-14.4).
 *
 * A receipt begins at controlled apply or recovery, never before, so preview
 * and pre-apply cancellation earn no receipt at all. That is deliberate rather
 * than an omission: `NoOp` means apply ran and found the declared end state
 * already satisfied, which is a different operational fact from an operator
 * declining at confirmation, and burying the difference would hide a
 * distinction this vocabulary exists to make.
 *
 * @api
 */
enum ChangeOutcome: string
{
    /** The declared end state was reached and is durable. */
    case Applied = 'applied';

    /** Controlled apply began and terminated with no durable change. */
    case NoOp = 'no_op';

    /** The authority declined before changing anything, with a typed code. */
    case Refused = 'refused';

    /** Neither the end state nor a clean restore of the prior one was reached. */
    case Failed = 'failed';

    /** The durable effect was resolving a prior interrupted attempt. */
    case Recovered = 'recovered';

    /**
     * The D-14.7 mapping from the generation binding's own vocabulary.
     *
     * Null is the mapping for the two outcomes that terminate before controlled
     * apply, not a failure to map them.
     */
    public static function forApplyOutcome(ArtifactApplyOutcome $outcome): ?self
    {
        return match ($outcome) {
            ArtifactApplyOutcome::Applied => self::Applied,
            ArtifactApplyOutcome::NoChanges => self::NoOp,
            ArtifactApplyOutcome::Refused => self::Refused,
            ArtifactApplyOutcome::Planned, ArtifactApplyOutcome::Cancelled => null,
        };
    }
}
