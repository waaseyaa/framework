<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract;

/** @api */
final readonly class ApplicationIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public string $canonicalOriginConfigKey,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'canonical_origin' => ['config_key' => $this->canonicalOriginConfigKey],
        ];
    }
}
