<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** MCP-neutral, principal-explicit content resource contribution. @api */
interface ContentResourceProviderInterface
{
    /** @return list<ContentResourceDescriptor> */
    public function list(AuthorizationPrincipalInterface $principal): array;

    /** @return list<ContentResourceTemplate> */
    public function templates(): array;

    /** Unknown or denied resources both return null. */
    public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent;
}
