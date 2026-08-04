<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token\Bearer;

/**
 * Sanitized durable-token-store failure (#2177 F3).
 *
 * Messages are fixed, operator-facing sentences; the driver-level cause (which
 * may carry a DSN or SQL fragment) travels only as the wrapped previous
 * exception for logs that explicitly opt into it.
 *
 * @api
 */
final class BearerTokenStoreException extends \RuntimeException {}
