<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema\Ai;

/** @api Closed outbound MCP authentication modes. */
enum McpAuthMode: string
{
    case None = 'none';
    case SecretReference = 'secret-reference';
}
