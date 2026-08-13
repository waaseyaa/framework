<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder\Surface;

use Waaseyaa\PageBuilder\Surface\Exception\UnknownPageBuilderSurfaceException;

/** @api */
final class PageBuilderSurfaceRegistry
{
    /** @var array<string, PageBuilderSurface> */
    private array $surfaces = [];

    public function register(string $id, PageBuilderSurface $surface): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/D', $id)) {
            throw new \InvalidArgumentException('Invalid page-builder surface identifier.');
        }
        if (isset($this->surfaces[$id])) {
            throw new \LogicException("Duplicate page-builder surface: {$id}");
        }
        $this->surfaces[$id] = $surface;
        ksort($this->surfaces, SORT_STRING);
    }

    public function get(string $id): PageBuilderSurface
    {
        return $this->surfaces[$id] ?? throw new UnknownPageBuilderSurfaceException("Unknown page-builder surface: {$id}");
    }
}
