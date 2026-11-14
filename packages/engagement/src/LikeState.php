<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

/** @api Desired-state result consumed by application renderers. */
final readonly class LikeState
{
    public function __construct(public bool $liked, public int $count) {}
}
