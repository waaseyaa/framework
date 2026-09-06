<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling\SpecCorpus;

/**
 * @api
 */
enum SpecLifecycle: string
{
    case Live = 'live';
    case Superseded = 'superseded';
    case Historical = 'historical';
    case Draft = 'draft';

    public static function fromString(string $value): self
    {
        return match ($value) {
            'live' => self::Live,
            'superseded' => self::Superseded,
            'historical' => self::Historical,
            'draft' => self::Draft,
            default => throw new SpecCorpusException("Unknown lifecycle: {$value}"),
        };
    }

    public function includedInDefaultIndex(): bool
    {
        return $this === self::Live;
    }
}
