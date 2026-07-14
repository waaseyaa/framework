<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Discovery;

/** @internal Boot-integrity diagnostic for the package discovery compiler. */
final class PolicyManifestMismatchException extends \RuntimeException
{
    /**
     * @param list<string> $expected
     * @param list<string> $discovered
     */
    public function __construct(
        array $expected,
        array $discovered,
    ) {
        $missing = array_values(array_diff($expected, $discovered));
        $unexpected = array_values(array_diff($discovered, $expected));

        parent::__construct(sprintf(
            'POLICY_MANIFEST_MISMATCH: discovered %d package access policies, manifest declares %d; refusing to boot. Missing: %s; unexpected: %s',
            count($discovered),
            count($expected),
            $missing === [] ? '(none)' : implode(', ', $missing),
            $unexpected === [] ? '(none)' : implode(', ', $unexpected),
        ));
    }
}
