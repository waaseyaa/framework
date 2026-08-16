<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** Binary-safe DSSE-style pre-authentication encoding for CFG-03. @api */
final class ConfigEnvelopePreAuthentication
{
    public const DOMAIN = 'waaseyaa.config-envelope-pae/1';

    public static function encode(string $protectedHeaderBytes, string $manifestBytes): string
    {
        return self::DOMAIN
            . ' ' . strlen($protectedHeaderBytes) . ' ' . $protectedHeaderBytes
            . ' ' . strlen($manifestBytes) . ' ' . $manifestBytes;
    }
}
