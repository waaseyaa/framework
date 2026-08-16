<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Binds the immutable bootstrap identity to the database's active generation. @api */
interface ConfigurationGenerationResolverInterface
{
    public function bind(ConfigurationAuthorityContext $context): ConfigurationAuthorityContext;
}
