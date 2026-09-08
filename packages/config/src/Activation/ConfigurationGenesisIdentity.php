<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Activation;

use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;

/** Canonical content-free generation identity shared by install and fresh activation. @api */
final class ConfigurationGenesisIdentity
{
    public static function generationId(ConfigurationAuthorityContext $context): string
    {
        return hash('sha256', 'configuration.genesis.empty.v1|' . $context->authorityId);
    }

    public static function token(ConfigurationAuthorityContext $context): ConfigurationActiveToken
    {
        return new ConfigurationActiveToken(self::generationId($context), 1);
    }
}
