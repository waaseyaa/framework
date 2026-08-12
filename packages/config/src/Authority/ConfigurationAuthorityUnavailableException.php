<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Stable fail-closed diagnostic for a missing authority successor binding. @api */
final class ConfigurationAuthorityUnavailableException extends \RuntimeException {}
