<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

/** A competing or stale signing-key lifecycle mutation won the authority fence. */
final class SigningKeyLifecycleConflictException extends \RuntimeException {}
