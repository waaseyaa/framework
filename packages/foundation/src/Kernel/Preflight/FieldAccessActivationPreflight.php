<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel\Preflight;

use Waaseyaa\Entity\Exception\FieldAccessActivationBlocked;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightData;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightResult;

/** Validates the exact checksum-bound deployment preflight at normal boot. @internal */
final readonly class FieldAccessActivationPreflight
{
    public function assertReady(string $projectRoot, string $frameworkVersion, string $schemaFingerprint): void
    {
        $path = rtrim($projectRoot, '/') . '/.waaseyaa/field-access-preflight.json';
        if (!is_file($path)) {
            throw new FieldAccessActivationBlocked('Field-read activation requires .waaseyaa/field-access-preflight.json.');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \UnexpectedValueException('The preflight root must be an object.');
            }
            $data = new FieldAccessPreflightData(
                frameworkVersion: $this->string($decoded, 'framework_version'),
                schemaFingerprint: $this->string($decoded, 'schema_fingerprint'),
                scannerVersion: $this->integer($decoded, 'scanner_version'),
                fields: $this->stringMap($decoded, 'fields'),
                conflicts: $this->stringList($decoded, 'conflicts'),
                unclassifiedEntries: $this->stringList($decoded, 'unclassified_entries'),
                v1Drivers: $this->stringList($decoded, 'v1_drivers'),
                serializedEntities: $this->stringList($decoded, 'serialized_entities'),
                legacyPayloads: $this->stringList($decoded, 'legacy_payloads'),
            );
            $result = FieldAccessPreflightResult::fromData($data);
            $artifactChecksum = $this->string($decoded, 'checksum');
        } catch (\Throwable $error) {
            throw new FieldAccessActivationBlocked('Field-read activation preflight artifact is malformed.', previous: $error);
        }

        if (!hash_equals($frameworkVersion, $data->frameworkVersion)
            || !hash_equals($schemaFingerprint, $data->schemaFingerprint)
        ) {
            throw new FieldAccessActivationBlocked('Field-read activation preflight is stale for the current framework or schema.');
        }
        if (!hash_equals($result->checksum, $artifactChecksum)
            || ($decoded['ready'] ?? null) !== $result->ready
        ) {
            throw new FieldAccessActivationBlocked('Field-read activation preflight checksum or readiness flag is invalid.');
        }

        $result->assertReadyForActivation();
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(sprintf('Preflight key "%s" must be a non-empty string.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException(sprintf('Preflight key "%s" must be an integer.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || !array_all($value, 'is_string')) {
            throw new \UnexpectedValueException(sprintf('Preflight key "%s" must be a string list.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    private function stringMap(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \UnexpectedValueException(sprintf('Preflight key "%s" must be an object.', $key));
        }
        foreach ($value as $name => $classification) {
            if (!is_string($name) || !is_string($classification)) {
                throw new \UnexpectedValueException(sprintf('Preflight key "%s" must be a string map.', $key));
            }
        }
        return $value;
    }
}
