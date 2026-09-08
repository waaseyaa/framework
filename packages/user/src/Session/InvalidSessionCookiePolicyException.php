<?php

declare(strict_types=1);

namespace Waaseyaa\User\Session;

/**
 * Thrown when `session.cookie` configuration (or an already-active PHP session)
 * cannot satisfy the requested cookie binding policy — especially the
 * `__Host-` host-bound profile (#3047).
 */
final class InvalidSessionCookiePolicyException extends \InvalidArgumentException {}
