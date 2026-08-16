<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

/** Refusal raised before configuration authority composition can diverge. @api */
final class ConfigurationAuthorityConflictException extends \RuntimeException {}
