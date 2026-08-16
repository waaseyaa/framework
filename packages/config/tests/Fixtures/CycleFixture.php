<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Fixtures;

use Waaseyaa\Config\Sync\ConfigSyncFile;

/**
 * Deliberate `role.admin → role.member → role.admin` cycle used by
 * mission validation (FR-056).
 *
 * Two simulated role entities whose declared sync-store dependencies form
 * a closed two-hop cycle. The dependency resolver MUST raise
 * {@see \Waaseyaa\Config\Dependency\Exception\ConfigDependencyCycleException}
 * with the full cycle path when given this declaration set, and the
 * `ConfigImporter` MUST surface the failure as a single
 * `STATUS_FAILED` entry without applying any sync file.
 *
 * The fixture deliberately uses the `role` entity-type slug because it is the
 * canonical example throughout this mission's spec and unit tests, and because
 * roles are a real Minoo config entity (so the cycle exercises a realistic
 * shape — not a synthetic `foo`/`bar` pairing).
 *
 * @api
 */
final class CycleFixture
{
    /**
     * Build the two cycle members as `ConfigSyncFile` values.
     *
     * @return array{role.admin: ConfigSyncFile, role.member: ConfigSyncFile}
     *         Returned by ref so callers can deposit them into a
     *         `ConfigSyncRepository` with `put()`.
     */
    public static function files(): array
    {
        $admin = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'admin',
            uuid: ConfigSyncFile::deterministicUuid('role', 'admin'),
            dependencies: ['role.member'],
            langcode: 'en',
            fields: ['label' => 'Admin'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        $member = ConfigSyncFile::writable(
            entityType: 'role',
            entityId: 'member',
            uuid: ConfigSyncFile::deterministicUuid('role', 'member'),
            dependencies: ['role.admin'],
            langcode: 'en',
            fields: ['label' => 'Member'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        return [
            'role.admin' => $admin,
            'role.member' => $member,
        ];
    }

    /**
     * Declaration map suitable for direct
     * {@see \Waaseyaa\Config\Dependency\DependencyResolver::resolve()} use.
     *
     * @return array<string, list<string>>
     */
    public static function declarations(): array
    {
        return [
            'role.admin' => ['role.member'],
            'role.member' => ['role.admin'],
        ];
    }
}
