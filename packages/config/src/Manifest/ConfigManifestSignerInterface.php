<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Manifest;

/** CFG-04-supplied signer; CFG-03 defines only the byte contract. @api */
interface ConfigManifestSignerInterface
{
    public function algorithm(): string;

    public function trustKeyReference(): string;

    /** Return raw signature bytes. */
    public function sign(string $preAuthenticatedBytes): string;
}
