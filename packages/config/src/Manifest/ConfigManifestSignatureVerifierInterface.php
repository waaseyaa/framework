<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** CFG-04-supplied trust resolver/verifier. @api */
interface ConfigManifestSignatureVerifierInterface
{
    /** Signature is raw bytes; key resolution must use only the supplied reference. */
    public function verify(string $trustKeyReference, string $algorithm, string $preAuthenticatedBytes, string $signature): bool;
}
