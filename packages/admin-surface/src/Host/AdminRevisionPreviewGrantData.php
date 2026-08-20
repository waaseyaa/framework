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
        if (!self::isSafePreviewUrl($previewUrl)) {
            throw new \InvalidArgumentException('Preview URL must be root-relative or HTTPS.');
        }
    }

    private static function isSafePreviewUrl(string $previewUrl): bool
    {
        if ($previewUrl === '') {
            return false;
        }

        $decoded = $previewUrl;
        for ($iteration = 0; $iteration < 5; ++$iteration) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if (rawurldecode($decoded) !== $decoded
            || preg_match('//u', $decoded) !== 1
            || preg_match('/[\x00-\x20\x7f]/', $decoded) === 1
            || str_contains($decoded, '\\')
        ) {
            return false;
        }

        $rootRelative = str_starts_with($decoded, '/') && !str_starts_with($decoded, '//');
        $https = str_starts_with($decoded, 'https://');
        if (!$rootRelative && !$https) {
            return false;
        }
        if ($https) {
            $parts = parse_url($decoded);
            if (!is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || !isset($parts['host'])
                || $parts['host'] === ''
            ) {
                return false;
            }
        }

        $path = (string) (parse_url($decoded, PHP_URL_PATH) ?? '');
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @return array{revisionId: int, previewUrl: string} */
    public function toArray(): array
    {
        return ['revisionId' => $this->revisionId, 'previewUrl' => $this->previewUrl];
    }
}
