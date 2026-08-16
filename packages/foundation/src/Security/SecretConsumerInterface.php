<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Purpose-specific same-process endpoint for guarded secret bytes.
 *
 * Consumer classes must be registered with the kernel-scoped resolver before
 * it is frozen. The contract is an accidental-disclosure boundary, not a PHP
 * sandbox against hostile same-process code or reflection.
 *
 * @template-covariant T
 * @api
 */
interface SecretConsumerInterface
{
    public static function id(): string;

    public static function secretClass(): SecretClass;

    public static function purpose(): string;

    /** @return T */
    public function consume(#[\SensitiveParameter] string $bytes, string $version): mixed;
}
