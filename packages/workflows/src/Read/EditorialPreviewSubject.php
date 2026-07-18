<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

/** Exact preview-authorization subject. @internal */
final readonly class EditorialPreviewSubject
{
    public function __construct(public int|string|null $authorId) {}
}
