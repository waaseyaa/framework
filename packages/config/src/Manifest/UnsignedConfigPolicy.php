<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** Sealed bootstrap decision; never derives unsigned authority from APP_ENV. @api */
final readonly class UnsignedConfigPolicy
{
    private function __construct(
        public string $authorityId,
        public bool $allowsUnsigned,
        public ?string $bootstrapSealHash,
    ) {}

    public static function refusing(string $authorityId): self
    {
        return new self(self::assertAuthority($authorityId), false, null);
    }

    public static function sealedLocal(string $authorityId, string $bootstrapSealHash): self
    {
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $bootstrapSealHash) !== 1) {
            throw new \InvalidArgumentException('Unsigned local policy requires a sealed bootstrap identity hash.');
        }

        return new self(self::assertAuthority($authorityId), true, $bootstrapSealHash);
    }

    private static function assertAuthority(string $authorityId): string
    {
        if ($authorityId === '' || preg_match('//u', $authorityId) !== 1) {
            throw new \InvalidArgumentException('Unsigned policy authority ID must be non-empty UTF-8.');
        }

        return $authorityId;
    }
}
