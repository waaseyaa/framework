<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Action;

use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;

interface SurfaceActionHandler
{
    /**
     * Handle a custom surface action.
     *
     * @param string $type The entity type ID
     * @param array<string, mixed> $payload The request payload
     */
    public function handle(string $type, array $payload): AdminSurfaceResultData;
}
