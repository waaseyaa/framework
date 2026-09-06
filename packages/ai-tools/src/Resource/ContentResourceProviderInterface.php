<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Resource;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** MCP-neutral, principal-explicit content resource contribution. @api */
interface ContentResourceProviderInterface
{
    /**
     * One bounded discovery page. {@see ContentResourceListPage::$next} is a
     * provider-local resume token; callers that expose it on a wire must seal it.
     */
    public function list(
        AuthorizationPrincipalInterface $principal,
        ?string $resumeToken = null,
    ): ContentResourceListPage;

    /** @return list<ContentResourceTemplate> */
    public function templates(): array;

    /** Unknown or denied resources both return null. */
    public function read(string $uri, AuthorizationPrincipalInterface $principal): ?ContentResourceContent;
}
