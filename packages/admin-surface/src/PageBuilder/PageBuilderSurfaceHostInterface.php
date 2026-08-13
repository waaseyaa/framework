<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\PageBuilder;

/** @api */
interface PageBuilderSurfaceHostInterface
{
    /** @return array<string, mixed> */
    public function handleDefinitions(PageBuilderSurfaceRequest $request, string $surface): array;

    /** @return array<string, mixed> */
    public function handleDraft(PageBuilderSurfaceRequest $request, string $surface, string $id): array;

    /** @return array<string, mixed> */
    public function handleCommand(PageBuilderSurfaceRequest $request, string $surface, string $id): array;

    /** @return array<string, mixed> */
    public function handlePreview(PageBuilderSurfaceRequest $request, string $surface, string $id): array;
}
