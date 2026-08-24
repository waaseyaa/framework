<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

/**
 * Reproduce proc_open()'s environment-REPLACEMENT semantics under Symfony
 * Process (#2491).
 *
 * proc_open() handed an explicit env array gives the child exactly that array
 * and nothing else. Symfony Process instead MERGES the array onto the inherited
 * environment — `$env += $this->getDefaultEnv()` in Process::start() — so a
 * variable a fixture deliberately withholds would leak in from whoever ran the
 * suite. Symfony's only removal mechanism is a `false` value, which
 * Process::start() filters out when it builds the child's env pairs.
 *
 * This is load-bearing rather than theoretical. Repository gate scripts read
 * ambient switches — `bin/check-package-layers` reads WAASEYAA_OUTPUT and emits
 * JSON when it is 'json' — so a merged environment silently changes a gate's
 * output format and breaks every string assertion made against it. Measured on
 * this repository: a merged conversion hands the child 60 variables where
 * proc_open() passed 2.
 *
 * Harnesses that passed NO env argument to proc_open() inherited the parent
 * environment and must keep passing null, not an empty array — they do not use
 * this trait.
 */
trait ReplacesProcessEnvironment
{
    /**
     * Pin every inherited variable the caller did not set to false, so the
     * child sees exactly $explicit.
     *
     * The key source is a superset of Process::getDefaultEnv() (which is
     * `$_ENV + array_intersect_key(getenv(), $_SERVER) ?: getenv()`), so no
     * inheritable name is missed. Unsetting a name that was never going to be
     * inherited is a no-op.
     *
     * @param  array<string, string> $explicit
     * @return array<string, string|false>
     */
    private static function replacingEnv(array $explicit): array
    {
        $env = $explicit;
        foreach (array_keys($_ENV + $_SERVER + getenv()) as $name) {
            if (!array_key_exists((string) $name, $env)) {
                $env[(string) $name] = false;
            }
        }

        return $env;
    }
}
