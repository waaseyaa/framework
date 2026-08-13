<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Preview;

/** @api */
interface RevisionPreviewUrlGeneratorInterface
{
    public function generate(
        string $entityId,
        int $revisionId,
        int $expiresAt,
        string $signature,
    ): string;
}
