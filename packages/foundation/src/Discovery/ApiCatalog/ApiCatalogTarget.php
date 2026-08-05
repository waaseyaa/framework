<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery\ApiCatalog;

/**
 * One same-origin target advertised by the public API catalog.
 *
 * Paths are deliberately root-relative. The serving package is solely
 * responsible for joining them to the deployment's configured canonical
 * base URL, so provider contributions can never inject a foreign origin or
 * reflect an untrusted Host header.
 *
 * @api
 */
final readonly class ApiCatalogTarget
{
    public function __construct(
        public string $path,
        public ?string $type = null,
        public ?string $title = null,
    ) {
        self::assertPath($path);
        self::assertMediaType($type);
        self::assertTitle($title);
    }

    /** @return array{path: string, type: ?string, title: ?string} */
    public function fingerprintData(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type,
            'title' => $this->title,
        ];
    }

    private static function assertPath(string $path): void
    {
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1
        ) {
            throw new \InvalidArgumentException('API catalog target must be a safe root-relative path.');
        }

        $parts = parse_url($path);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('API catalog target must stay on the configured canonical origin.');
        }
    }

    private static function assertMediaType(?string $type): void
    {
        if ($type === null) {
            return;
        }
        if (
            preg_match('/[\x00-\x1f\x7f]/', $type) === 1
            || preg_match('/^[A-Za-z0-9!#$&^_.+\-]+\/[A-Za-z0-9!#$&^_.+\-]+$/', $type) !== 1
        ) {
            throw new \InvalidArgumentException('API catalog target media type is malformed.');
        }
    }

    private static function assertTitle(?string $title): void
    {
        if ($title !== null && preg_match('/[\x00-\x1f\x7f]/', $title) === 1) {
            throw new \InvalidArgumentException('API catalog target title contains control characters.');
        }
    }
}
