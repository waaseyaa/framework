<?php

declare(strict_types=1);

namespace Waaseyaa\Routing;

use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

/** Only supplies the kernel's language-stripped path; Symfony retains request/context handling. @internal */
final class RequestPathMatcher extends UrlMatcher
{
    public function __construct(RouteCollection $routes, RequestContext $context, private readonly ?string $pathOverride)
    {
        parent::__construct($routes, $context);
    }

    public function match(string $pathinfo): array
    {
        return parent::match($this->pathOverride ?? $pathinfo);
    }
}
