<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @api */
final readonly class PageBuilderSurfaceRequest
{
    public function __construct(
        public ?AuthorizationPrincipalInterface $actor,
        public string $content = '',
    ) {}
}
