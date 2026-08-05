<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/** Validated protocol era and version for one request. */
final readonly class McpRequestContext
{
    public function __construct(public bool $modern) {}
}
