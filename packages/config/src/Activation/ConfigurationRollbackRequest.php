<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;

/** Separately validated request to reactivate retained configuration content. @api */
final readonly class ConfigurationRollbackRequest
{
    public function __construct(
        public string $requestId,
        public ConfigurationActiveToken $expectedToken,
        public ConfigurationActiveToken $targetToken,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Configuration rollback request IDs must be stable printable identifiers.');
        }
    }
}
