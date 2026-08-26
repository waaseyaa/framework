<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Validated same-origin redirect metadata. @api */
final readonly class AuthRedirect
{
    public function __construct(public string $path)
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || parse_url($path, PHP_URL_SCHEME) !== null
            || parse_url($path, PHP_URL_HOST) !== null) {
            throw new \InvalidArgumentException('Auth redirect must be a same-origin absolute path.');
        }
    }
}
