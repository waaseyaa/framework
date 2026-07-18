<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

/** Opaque registrar-issued backend role; constructing a handle grants no authority. @api */
final class FieldStorageGatewayRole
{
    private ?FieldStorageGatewayAuthority $authority = null;

    /** @internal Called only by the registrar-owned gateway authority. */
    public static function forAuthority(FieldStorageGatewayAuthority $authority): self
    {
        $role = new self();
        $role->authority = $authority;

        return $role;
    }

    public function unwrap(
        FieldStorageGatewayInput $input,
        FieldStorageBackendV2Interface $backend,
    ): FieldStorageGatewayInvocation {
        return $this->requiredAuthority()->unwrap($this, $input, $backend);
    }

    public function complete(
        FieldStorageGatewayInput $input,
        FieldStorageBackendV2Interface $backend,
        mixed $value,
    ): FieldStorageGatewayOutput {
        return $this->requiredAuthority()->complete($this, $input, $backend, $value);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Field-storage gateway roles cannot be serialized.');
    }

    private function requiredAuthority(): FieldStorageGatewayAuthority
    {
        return $this->authority
            ?? throw new \LogicException('A registrar-issued field-storage gateway role is required.');
    }
}
