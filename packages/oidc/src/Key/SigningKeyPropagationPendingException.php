<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Key;

/** A staged successor has not completed its evidenced JWKS cache horizon. */
final class SigningKeyPropagationPendingException extends \RuntimeException {}
