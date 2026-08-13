<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

/** @api */
final readonly class ContentTypeDeclaration
{
    public function __construct(
        public string $id,
        public string $canonicalRoute,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'canonical_route' => $this->canonicalRoute];
    }
}
