<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\Sync\ConfigSyncFile;

/** Fail-closed boundary between immutable bootstrap and deployable configuration. @api */
final class DeployableConfigurationPolicy
{
    private const array BOOTSTRAP_NAMES = [
        'database',
        'environment',
        'config',
        'config.sync_path',
        'config.allow_external_sync_path',
    ];

    private const array BOOTSTRAP_ENTITY_TYPES = [
        'bootstrap',
        'database',
        'environment',
        'secret',
    ];

    private const array RAW_SECRET_FIELD_NAMES = [
        'api_key',
        'auth_header',
        'authorization',
        'bearer_token',
        'client_secret',
        'credential',
        'password',
        'passwd',
        'private_key',
        'refresh_token',
        'secret',
        'token',
        'access_token',
    ];

    private const array REFERENCE_SUFFIXES = [
        '_credential_ref',
        '_env_var',
        '_key_ref',
        '_secret_ref',
        '_token_ref',
    ];

    public static function assertDeployableName(string $name): void
    {
        $normalized = strtolower(trim($name));
        $entityType = str_contains($normalized, '.') ? strstr($normalized, '.', true) : $normalized;
        if (in_array($normalized, self::BOOTSTRAP_NAMES, true)
            || in_array($entityType, self::BOOTSTRAP_ENTITY_TYPES, true)
        ) {
            throw new ConfigurationAuthorityConflictException(sprintf(
                'Configuration entry "%s" belongs to immutable bootstrap authority and cannot be deployed.',
                $name,
            ));
        }
    }

    public static function assertDeployableFile(ConfigSyncFile $file): void
    {
        self::assertDeployableName($file->ref());
        self::assertReferenceOnlyFields($file->fields);
    }

    /** @param array<string, mixed> $fields */
    public static function assertReferenceOnlyFields(array $fields, string $path = 'fields'): void
    {
        foreach ($fields as $key => $value) {
            $name = strtolower((string) $key);
            $fieldPath = $path . '.' . $name;
            if (in_array($name, self::RAW_SECRET_FIELD_NAMES, true) && !self::isReferenceField($name)) {
                throw new ConfigurationAuthorityConflictException(sprintf(
                    'Secret-typed configuration field "%s" must contain an opaque reference field, never secret bytes.',
                    $fieldPath,
                ));
            }
            if (is_array($value)) {
                self::assertReferenceOnlyFields($value, $fieldPath);
            }
        }
    }

    private static function isReferenceField(string $name): bool
    {
        foreach (self::REFERENCE_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

