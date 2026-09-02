<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Blueprint;

/** @api */
final readonly class BlueprintEntityKeys
{
    public function __construct(
        public string $id,
        public string $uuid,
        public string $label,
        public ?string $revision = null,
        public ?string $langcode = null,
        public ?string $defaultLangcode = null,
        public ?string $owner = null,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        $result = ['id' => $this->id, 'uuid' => $this->uuid, 'label' => $this->label];
        if ($this->revision !== null) {
            $result['revision'] = $this->revision;
        }
        if ($this->langcode !== null) {
            $result['langcode'] = $this->langcode;
        }
        if ($this->defaultLangcode !== null) {
            $result['default_langcode'] = $this->defaultLangcode;
        }
        if ($this->owner !== null) {
            $result['owner'] = $this->owner;
        }

        return $result;
    }
}
