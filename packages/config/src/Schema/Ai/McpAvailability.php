<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

/** @api Closed outbound MCP readiness policies. */
enum McpAvailability: string
{
    case Required = 'required';
    case Optional = 'optional';
}
