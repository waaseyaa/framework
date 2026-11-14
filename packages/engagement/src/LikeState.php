<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

final readonly class LikeState
{
    public function __construct(public bool $liked, public int $count) {}
}
