<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/**
 * Fingerprinted privileged field-storage implementation contract.
 *
 * Implementations receive only registrar-issued roles and opaque inputs. They
 * MUST unwrap an input through the supplied role before performing any read or
 * write and MUST construct the opaque result through that same role.
 *
 * @api
 */
interface FieldStorageBackendV2Interface
{
    public function id(): string;

    /** Stable reviewed implementation/configuration fingerprint. */
    public function fingerprint(): string;

    public function invoke(
        FieldStorageGatewayRole $gateway,
        FieldStorageGatewayInput $input,
    ): FieldStorageGatewayOutput;
}
