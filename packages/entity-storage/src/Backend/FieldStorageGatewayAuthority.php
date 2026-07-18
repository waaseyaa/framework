<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Query\EntityQuery;
use Waaseyaa\Field\FieldDefinition;

/** @internal Registrar-owned object-identity authority for one backend gateway. */
final class FieldStorageGatewayAuthority
{
    /** @var \WeakMap<FieldStorageGatewayInput, FieldStorageGatewayInvocation> */
    private \WeakMap $inputs;

    /** @var \WeakMap<FieldStorageGatewayOutput, array{input: FieldStorageGatewayInput, value: mixed}> */
    private \WeakMap $outputs;

    private readonly FieldStorageGatewayRole $role;

    public function __construct(
        private readonly FieldStorageBackendV2Interface $backend,
        private readonly string $backendId,
        private readonly string $fingerprint,
    ) {
        $this->inputs = new \WeakMap();
        $this->outputs = new \WeakMap();
        $this->role = FieldStorageGatewayRole::forAuthority($this);
    }

    public function role(): FieldStorageGatewayRole
    {
        return $this->role;
    }

    public function assertFreshFingerprint(): void
    {
        if (!hash_equals($this->fingerprint, $this->backend->fingerprint())) {
            throw new \LogicException(sprintf('Field-storage backend "%s" fingerprint changed after registration.', $this->backendId));
        }
    }

    public function input(
        FieldStorageGatewayOperation $operation,
        ?EntityInterface $entity,
        ?FieldDefinition $field,
        mixed $value,
        ?EntityQuery $query,
    ): FieldStorageGatewayInput {
        $input = new FieldStorageGatewayInput();
        $this->inputs[$input] = new FieldStorageGatewayInvocation($operation, $entity, $field, $value, $query);

        return $input;
    }

    public function unwrap(
        FieldStorageGatewayRole $role,
        FieldStorageGatewayInput $input,
        FieldStorageBackendV2Interface $backend,
    ): FieldStorageGatewayInvocation {
        $this->assertRoleAndBackend($role, $backend);

        return $this->inputs[$input]
            ?? throw new \LogicException('A boundary-bound field-storage gateway input is required.');
    }

    public function complete(
        FieldStorageGatewayRole $role,
        FieldStorageGatewayInput $input,
        FieldStorageBackendV2Interface $backend,
        mixed $value,
    ): FieldStorageGatewayOutput {
        $this->assertRoleAndBackend($role, $backend);
        if (!isset($this->inputs[$input])) {
            throw new \LogicException('A boundary-bound field-storage gateway input is required.');
        }
        $output = new FieldStorageGatewayOutput();
        $this->outputs[$output] = ['input' => $input, 'value' => $value];

        return $output;
    }

    public function output(FieldStorageGatewayInput $input, FieldStorageGatewayOutput $output): mixed
    {
        $state = $this->outputs[$output]
            ?? throw new \LogicException('A boundary-bound field-storage gateway output is required.');
        if ($state['input'] !== $input) {
            throw new \LogicException('Field-storage gateway output belongs to a different invocation.');
        }

        return $state['value'];
    }

    private function assertRoleAndBackend(FieldStorageGatewayRole $role, FieldStorageBackendV2Interface $backend): void
    {
        if ($role !== $this->role || $backend !== $this->backend) {
            throw new \LogicException('A registrar-issued field-storage gateway role is required.');
        }
    }
}
