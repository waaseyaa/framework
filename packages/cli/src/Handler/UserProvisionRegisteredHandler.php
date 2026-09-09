<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Handler;

use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleProvisioningResult;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\SiteContract\CanonicalJson;

/** Provisions one account and registered role from a bounded private stdin document. @api */
final readonly class UserProvisionRegisteredHandler
{
    public const MAX_INPUT_BYTES = 4096;
    public const INPUT_SCHEMA = 'waaseyaa.user-provision-registered-command.v1';

    public function __construct(
        private EntityTypeManagerInterface $entityTypeManager,
        private RegisteredRoleAccountProvisioner $provisioner,
        private string $stdinPath = 'php://stdin',
    ) {}

    public function execute(SymfonyCommandIO $io): int
    {
        $payload = $this->readPayload();
        if (!is_array($payload)) {
            return $this->finish($io, new RegisteredRoleProvisioningResult('refused', 'invalid_input', ''));
        }

        if (!$this->validEnvelope($payload)) {
            return $this->finish($io, new RegisteredRoleProvisioningResult('refused', 'invalid_input', ''));
        }

        try {
            $result = $this->provisioner->provision(
                $this->entityTypeManager->getRepository('user'),
                $payload['username'],
                $payload['email'],
                $payload['role'],
                $payload['password'],
            );
        } catch (\Throwable) {
            $result = new RegisteredRoleProvisioningResult('refused', 'storage_failure', '');
        }

        return $this->finish($io, $result);
    }

    /** @return array<string, mixed>|null */
    private function readPayload(): ?array
    {
        $handle = @fopen($this->stdinPath, 'rb');
        if (!is_resource($handle)) {
            return null;
        }
        try {
            $raw = stream_get_contents($handle, self::MAX_INPUT_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($raw) || strlen($raw) > self::MAX_INPUT_BYTES) {
            return null;
        }

        return $this->decode($raw);
    }

    /** @return array<string, mixed>|null */
    private function decode(#[\SensitiveParameter] string $raw): ?array
    {
        try {
            $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || array_is_list($payload)) {
                return null;
            }
            if (!hash_equals(CanonicalJson::encode($payload) . "\n", $raw)) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function validEnvelope(#[\SensitiveParameter] array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['email', 'password', 'role', 'schema', 'username', 'version']) {
            return false;
        }

        return $payload['schema'] === self::INPUT_SCHEMA
            && $payload['version'] === 1
            && is_string($payload['username'])
            && is_string($payload['email'])
            && is_string($payload['role'])
            && is_string($payload['password']);
    }

    private function finish(SymfonyCommandIO $io, RegisteredRoleProvisioningResult $result): int
    {
        $io->writeRaw($result->canonicalJson() . "\n");

        return match ($result->status) {
            'created', 'existing' => 0,
            'refused' => 1,
            'uncertain' => 2,
            default => throw new \LogicException('Invalid provisioning result status.'),
        };
    }
}
