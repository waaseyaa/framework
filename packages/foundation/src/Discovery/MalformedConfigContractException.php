<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/**
 * An installed package declares `extra.waaseyaa.config-contract` and got it
 * wrong (#2430).
 *
 * This is deliberately not the same as declaring nothing. A package that
 * contributes no configuration contract is complete and ordinary; a package
 * that declares one nobody can read is evidence that the installed cohort is
 * not what it claims to be. Demoting the second to the first would produce an
 * under-specified compatibility cohort that both the signer and the verifier
 * would accept, so discovery fails closed and names the package and the field.
 *
 * @api
 */
final class MalformedConfigContractException extends \RuntimeException
{
    public static function forField(string $package, string $field, string $requirement): self
    {
        return new self(sprintf(
            'Package %s declares a malformed extra.waaseyaa.config-contract: "%s" %s. '
            . 'Fix the declaration in that package\'s composer.json; configuration discovery, signing, and import all refuse until it is valid.',
            $package,
            $field,
            $requirement,
        ));
    }
}
