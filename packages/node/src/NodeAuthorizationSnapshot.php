<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

/** Exact immutable inputs used by the legacy account-policy adapter. @internal */
final readonly class NodeAuthorizationSnapshot
{
    public function __construct(
        public string $type,
        public int|string|null $authorId,
        public bool $published,
    ) {}
}
