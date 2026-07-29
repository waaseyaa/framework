<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

/**
 * A structured asset-validation failure (bad type, oversize, unreadable).
 *
 * @api
 */
final class AssetRejectedException extends \RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Asset rejected: ' . implode(' ', $reasons));
    }
}
