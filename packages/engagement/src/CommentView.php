<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

/**
 * Plain text projection. Renderers must escape text for their output context.
 *
 * @api Serialized discussion projection consumed by application renderers.
 */
final readonly class CommentView
{
    public function __construct(
        public string $uuid,
        public ?int $authorId,
        public ?string $text,
        public ?int $createdAt,
        public ?int $editedAt,
        public ?int $revision,
        public bool $placeholder = false,
        public int $replyCount = 0,
        public bool $replayed = false,
    ) {}
}
