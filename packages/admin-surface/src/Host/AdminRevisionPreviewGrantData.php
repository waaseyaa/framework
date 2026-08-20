<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

/** Exact-revision preview grant returned by an application authority. @api */
final readonly class AdminRevisionPreviewGrantData
{
    public function __construct(
        public int $revisionId,
        public string $previewUrl,
    ) {
        if ($revisionId < 1) {
            throw new \InvalidArgumentException('Preview revision id must be positive.');
        }
        if ($previewUrl === '' || str_starts_with($previewUrl, '//')
            || (!str_starts_with($previewUrl, '/') && !str_starts_with($previewUrl, 'https://'))
        ) {
            throw new \InvalidArgumentException('Preview URL must be root-relative or HTTPS.');
        }
    }

    /** @return array{revisionId: int, previewUrl: string} */
    public function toArray(): array
    {
        return ['revisionId' => $this->revisionId, 'previewUrl' => $this->previewUrl];
    }
}
