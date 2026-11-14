<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

final readonly class CommentPage
{
    /** @param list<CommentView> $items */
    public function __construct(public array $items, public ?string $nextCursor, public bool $hasMore) {}
}
