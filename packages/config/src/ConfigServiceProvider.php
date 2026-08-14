<?php

declare(strict_types=1);

namespace Waaseyaa\Config;

use Waaseyaa\Config\Authority\ConfigurationAuthorityServiceProvider;

/** @deprecated Use ConfigurationAuthorityServiceProvider. */
class_alias(ConfigurationAuthorityServiceProvider::class, __NAMESPACE__ . '\\ConfigServiceProvider');
