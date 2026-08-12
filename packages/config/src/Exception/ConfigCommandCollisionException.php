<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Exception;

/**
 * Raised at kernel boot when an app- or extension-registered command
 * collides with a reserved `config:*` verb.
 *
 * The framework reserves the verbs `config:export`, `config:import`,
 * `config:diff`, `config:status`, `config:validate`, `config:reset`
 * (see `Waaseyaa\CLI\Command\Config\ConfigCommand::RESERVED_VERBS`).
 *
 * Apps and extensions MAY register `config:<custom>` verbs that are NOT
 * in the reserved set (FR-049, e.g. `config:audit-export`,
 * `config:lint`). Registering a verb that IS in the reserved set from
 * outside the framework's allowlist (`ConfigCommand::RESERVED_FQCNS`)
 * raises this exception and refuses kernel boot (FR-048).
 *
 * Stability scope (charter §5.5): the exception FQCN, the `errorCode`
 * (`'config.cli.collision'`), `reservedVerb`, `offendingFqcn`, and the
 * constructor signature are on stable surface. The integer
 * `\Exception::$code` is always `0` — consume `errorCode` for routing.
 *
 * @api
 */
final class ConfigCommandCollisionException extends \LogicException
{
    /** Stable error-code string for routing / structured logging. */
    public const ERROR_CODE = 'config.cli.collision';

    /** Stable error-code string (companion to the inherited integer `$code`). */
    public readonly string $errorCode;

    /**
     * @param string $reservedVerb  The colliding verb (e.g. `'config:export'`).
     * @param string $offendingFqcn The FQCN of the colliding command class.
     */
    public function __construct(
        public readonly string $reservedVerb,
        public readonly string $offendingFqcn,
    ) {
        $this->errorCode = self::ERROR_CODE;

        parent::__construct(sprintf(
            'CLI command "%s" is reserved by the framework. '
            . 'The class "%s" cannot register this verb because it is not part of the '
            . 'framework\'s reserved-verb allowlist. '
            . 'Apps MAY register non-reserved "config:<custom>" verbs instead.',
            $reservedVerb,
            $offendingFqcn,
        ));
    }

    /**
     * Convenience factory that mirrors the call site at the kernel boot hook.
     */
    public static function forVerb(string $reservedVerb, string $offendingFqcn): self
    {
        return new self($reservedVerb, $offendingFqcn);
    }

    public static function duplicateVerb(string $reservedVerb, string $offendingFqcn, string $existingFqcn): self
    {
        $exception = new self($reservedVerb, $offendingFqcn);
        $exception->message = sprintf(
            'CLI command "%s" was registered more than once (%s and %s); reserved config verbs require exactly one adapter.',
            $reservedVerb,
            $existingFqcn,
            $offendingFqcn,
        );

        return $exception;
    }
}
