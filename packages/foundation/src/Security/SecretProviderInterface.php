<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Trusted ingress adapter for one externally held secret provider.
 *
 * Implementations transfer raw bytes directly into SensitiveValue custody and
 * must not place provider error text or secret material in thrown messages.
 *
 * @api
 */
interface SecretProviderInterface
{
    public function id(): string;

    public function resolve(SecretReference $reference): SensitiveValue;
}
