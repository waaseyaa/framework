<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Extension;

/** Provider capability for narrow application-owned auth policy. @api */
interface ProvidesAuthExtensionsInterface
{
    public function authExtensions(): AuthExtensionContribution;
}
