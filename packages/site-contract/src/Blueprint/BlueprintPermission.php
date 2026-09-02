<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintPermission
{
    public function __construct(
        public string $id,
        public string $title,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title];
    }
}
