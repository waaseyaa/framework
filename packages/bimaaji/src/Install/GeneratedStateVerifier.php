<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Read-only generated-state verification for Bimaaji install output.
 *
 * Composes the installed ownership manifest (schema 1 wholefile sha1
 * provenance), the current transformer render set, and
 * {@see ManagedRegion} freshness checks. It does not write, migrate, or
 * repair anything — that lifecycle remains #2664 residual scope beyond this
 * bounded read surface.
 *
 * @api
 */
final class GeneratedStateVerifier
{
    /** @var array<string, ClientTransformerInterface> */
    private readonly array $transformersByClientId;

    private readonly InstallPathSandbox $sandbox;

    /**
     * @param iterable<ClientTransformerInterface> $transformers
     */
    public function __construct(
        iterable $transformers,
        private readonly SkillSetParser $skillSetParser,
        ?InstallPathSandbox $sandbox = null,
    ) {
        $map = [];
        foreach ($transformers as $transformer) {
            $map[$transformer->clientId()] = $transformer;
        }
        ksort($map);
        $this->transformersByClientId = $map;
        $this->sandbox = $sandbox ?? new InstallPathSandbox();
    }

    /**
     * @param list<string>|null $clientFilter When non-null, verify only these client ids.
     */
    public function verify(string $projectRoot, ?array $clientFilter = null): VerifyReport
    {
        $findings = [];
        foreach ($clientFilter ?? [] as $clientId) {
            if (!isset($this->transformersByClientId[$clientId])) {
                $findings[] = new VerifyFinding(
                    code: VerifyFindingCode::UnknownClient,
                    clientId: $clientId,
                    detail: 'requested client is not registered',
                );
            }
        }
        if ($findings !== []) {
            return new VerifyReport(
                manifestStatus: ManifestReadStatus::Ok,
                findings: $findings,
                manifestDetail: 'not evaluated',
            );
        }
        $targetStatuses = [];
        $manifestResult = InstalledManifest::readStrict($projectRoot, $this->sandbox);

        if (!$manifestResult->isOk()) {
            $findings[] = $this->manifestFinding($manifestResult);

            return new VerifyReport(
                manifestStatus: $manifestResult->status,
                findings: $findings,
                manifestDetail: $manifestResult->detail,
            );
        }

        $manifest = $manifestResult->manifest;
        $recordedClientIds = $manifest->clientIds();
        $selectionFindings = $this->resolveSelectionFindings($recordedClientIds, $clientFilter);
        $findings = array_merge($findings, $selectionFindings);
        $clientIds = $this->resolveClientIds($recordedClientIds, $clientFilter);
        $verifiedClients = $clientFilter ?? $clientIds;
        if ($clientFilter !== null) {
            sort($verifiedClients);
        }

        if ($clientIds === [] && $selectionFindings !== []) {
            usort(
                $findings,
                static fn(VerifyFinding $a, VerifyFinding $b): int => [$a->code->value, $a->clientId ?? '', $a->path ?? '']
                    <=> [$b->code->value, $b->clientId ?? '', $b->path ?? ''],
            );

            return new VerifyReport(
                manifestStatus: ManifestReadStatus::Ok,
                findings: $findings,
                verifiedClients: $verifiedClients,
                manifestDetail: $clientFilter === null ? 'no recorded installation' : null,
            );
        }

        try {
            $skills = SkillInventory::fromParser($this->skillSetParser)->all();
        } catch (SkillResourceException $exception) {
            return new VerifyReport(
                manifestStatus: ManifestReadStatus::Ok,
                findings: array_merge($findings, [new VerifyFinding(
                    code: VerifyFindingCode::SkillSourceFailure,
                    detail: $this->skillSourceDetail($exception),
                )]),
                verifiedClients: $verifiedClients,
            );
        }

        foreach ($clientIds as $clientId) {
            if (!isset($this->transformersByClientId[$clientId])) {
                $findings[] = new VerifyFinding(
                    code: VerifyFindingCode::UnknownClient,
                    clientId: $clientId,
                    detail: 'No registered transformer for a manifest-recorded client.',
                );
                continue;
            }

            $clientResult = $this->verifyClient(
                clientId: $clientId,
                transformer: $this->transformersByClientId[$clientId],
                skills: $skills,
                projectRoot: $projectRoot,
                recorded: $manifest->targetsFor($clientId),
            );
            $findings = array_merge($findings, $clientResult['findings']);
            $targetStatuses = array_merge($targetStatuses, $clientResult['statuses']);
        }

        usort(
            $findings,
            static fn(VerifyFinding $a, VerifyFinding $b): int => [$a->code->value, $a->clientId ?? '', $a->path ?? '']
                <=> [$b->code->value, $b->clientId ?? '', $b->path ?? ''],
        );

        usort(
            $targetStatuses,
            static fn(VerifyTargetStatus $a, VerifyTargetStatus $b): int => [$a->clientId, $a->path] <=> [$b->clientId, $b->path],
        );

        return new VerifyReport(
            manifestStatus: ManifestReadStatus::Ok,
            findings: $findings,
            verifiedClients: $verifiedClients,
            targetStatuses: $targetStatuses,
        );
    }

    /**
     * @param list<ParsedSkill> $skills
     * @param array<string, string> $recorded
     * @return array{findings: list<VerifyFinding>, statuses: list<VerifyTargetStatus>}
     */
    private function verifyClient(
        string $clientId,
        ClientTransformerInterface $transformer,
        array $skills,
        string $projectRoot,
        array $recorded,
    ): array {
        $findings = [];
        $statuses = [];
        $declared = [];
        foreach ($transformer->targetFiles($skills) as $file) {
            $declared[$file->path] = $file;
        }
        ksort($declared);

        foreach ($recorded as $path => $recordedSha1) {
            if (!isset($declared[$path])) {
                $resolved = $this->sandbox->resolveContainedPath($path, $projectRoot);
                if ($resolved === null) {
                    $findings[] = new VerifyFinding(
                        code: VerifyFindingCode::ManifestTargetEscapes,
                        clientId: $clientId,
                        path: $path,
                    );
                } elseif (is_file($resolved)) {
                    $findings[] = new VerifyFinding(
                        code: VerifyFindingCode::TargetRetiredPresent,
                        clientId: $clientId,
                        path: $path,
                        detail: 'Recorded in the manifest but absent from the current render set.',
                    );
                }
                continue;
            }

            $targetResult = $this->verifyRecordedTarget(
                clientId: $clientId,
                path: $path,
                recordedSha1: $recordedSha1,
                expected: $declared[$path],
                projectRoot: $projectRoot,
            );
            $findings = array_merge($findings, $targetResult['findings']);
            if ($targetResult['status'] !== null) {
                $statuses[] = $targetResult['status'];
            }
        }

        foreach ($declared as $path => $expected) {
            if (!isset($recorded[$path])) {
                $findings[] = new VerifyFinding(
                    code: VerifyFindingCode::TargetUnrecorded,
                    clientId: $clientId,
                    path: $path,
                    detail: 'Present in the current render set but absent from the ownership manifest.',
                );
            }
        }

        return ['findings' => $findings, 'statuses' => $statuses];
    }

    /**
     * @return array{findings: list<VerifyFinding>, status: ?VerifyTargetStatus}
     */
    private function verifyRecordedTarget(
        string $clientId,
        string $path,
        string $recordedSha1,
        TargetFile $expected,
        string $projectRoot,
    ): array {
        $findings = [];
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false) {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::TargetUnreadable,
                clientId: $clientId,
                path: $path,
            );

            return ['findings' => $findings, 'status' => null];
        }

        $resolved = $this->sandbox->resolveContainedPath($path, $resolvedRoot);
        if ($resolved === null) {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::ManifestTargetEscapes,
                clientId: $clientId,
                path: $path,
            );

            return ['findings' => $findings, 'status' => null];
        }

        if (!is_file($resolved)) {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::TargetMissing,
                clientId: $clientId,
                path: $path,
            );

            return ['findings' => $findings, 'status' => null];
        }

        $actual = $this->sandbox->readBoundedFile($resolved);
        if ($actual === null) {
            $findings[] = new VerifyFinding(
                code: $this->oversizeTarget($resolved)
                    ? VerifyFindingCode::TargetOversize
                    : VerifyFindingCode::TargetUnreadable,
                clientId: $clientId,
                path: $path,
                detail: $this->oversizeTarget($resolved)
                    ? sprintf('Exceeds the %d-byte verification bound.', InstallPathSandbox::MAX_READ_BYTES)
                    : null,
            );

            return ['findings' => $findings, 'status' => null];
        }

        $actualSha1 = sha1($actual);
        $managed = $this->assessManagedRegion($actual, $expected->content);
        if ($managed === 'drift') {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::TargetManagedRegionDrift,
                clientId: $clientId,
                path: $path,
                detail: 'Managed region does not match the current transformer render.',
            );
        } elseif ($managed === 'unprovable') {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::TargetManagedRegionUnprovable,
                clientId: $clientId,
                path: $path,
                detail: 'Cannot assess managed-region freshness without a single well-ordered marker pair.',
            );
        }

        if (!hash_equals($recordedSha1, $actualSha1) && $managed !== 'ok') {
            $findings[] = new VerifyFinding(
                code: VerifyFindingCode::TargetWholefileDrift,
                clientId: $clientId,
                path: $path,
                detail: 'Wholefile sha1 differs from the schema 1 manifest record.',
            );
        }

        if ($findings !== []) {
            return ['findings' => $findings, 'status' => null];
        }

        $wholefile = hash_equals($recordedSha1, $actualSha1) ? 'match' : 'outer_edit';

        return [
            'findings' => [],
            'status' => new VerifyTargetStatus(
                clientId: $clientId,
                path: $path,
                wholefile: $wholefile,
                managedRegion: $managed,
            ),
        ];
    }

    /**
     * @return 'ok'|'drift'|'unprovable'
     */
    private function assessManagedRegion(string $actual, string $generated): string
    {
        $expectedOnDisk = ManagedRegion::splice($actual, $generated);
        if ($expectedOnDisk === null) {
            if (ManagedRegion::extract($actual) === null) {
                return 'unprovable';
            }

            return 'drift';
        }

        return hash_equals($actual, $expectedOnDisk) ? 'ok' : 'drift';
    }

    private function oversizeTarget(string $resolvedPath): bool
    {
        $size = filesize($resolvedPath);

        return $size !== false && $size > InstallPathSandbox::MAX_READ_BYTES;
    }

    private function skillSourceDetail(SkillResourceException $exception): string
    {
        return match ($exception->failure) {
            SkillResourceFailure::Missing => 'canonical skill resources are missing or unreadable',
            SkillResourceFailure::Corrupt => 'a canonical skill document is corrupt',
        };
    }

    private function manifestFinding(InstalledManifestReadResult $result): VerifyFinding
    {
        return match ($result->status) {
            ManifestReadStatus::Missing => new VerifyFinding(
                code: VerifyFindingCode::ManifestMissing,
                path: InstalledManifest::RELATIVE_PATH,
                detail: $result->detail,
            ),
            ManifestReadStatus::Unreadable => new VerifyFinding(
                code: VerifyFindingCode::ManifestUnreadable,
                path: InstalledManifest::RELATIVE_PATH,
                detail: $result->detail,
            ),
            ManifestReadStatus::Malformed => new VerifyFinding(
                code: VerifyFindingCode::ManifestMalformed,
                path: InstalledManifest::RELATIVE_PATH,
                detail: $result->detail,
            ),
            ManifestReadStatus::UnsupportedSchema => new VerifyFinding(
                code: VerifyFindingCode::ManifestUnsupportedSchema,
                path: InstalledManifest::RELATIVE_PATH,
                detail: $result->detail,
            ),
            ManifestReadStatus::Ok => throw new \LogicException('Ok manifest cannot produce a manifest finding.'),
        };
    }

    /**
     * @param list<string> $recordedClientIds
     * @param list<string>|null $clientFilter
     * @return list<VerifyFinding>
     */
    private function resolveSelectionFindings(array $recordedClientIds, ?array $clientFilter): array
    {
        if ($clientFilter === null) {
            if ($recordedClientIds === []) {
                return [new VerifyFinding(
                    code: VerifyFindingCode::NoRecordedInstallation,
                    detail: 'the ownership manifest records no installed clients',
                )];
            }

            return [];
        }

        $findings = [];
        $recorded = array_flip($recordedClientIds);
        foreach ($clientFilter as $requested) {
            if (!isset($this->transformersByClientId[$requested])) {
                $findings[] = new VerifyFinding(
                    code: VerifyFindingCode::UnknownClient,
                    clientId: $requested,
                    detail: 'requested client is not registered',
                );
                continue;
            }
            if (isset($recorded[$requested])) {
                continue;
            }

            if (isset($this->transformersByClientId[$requested])) {
                $findings[] = new VerifyFinding(
                    code: VerifyFindingCode::ClientNotInstalled,
                    clientId: $requested,
                    detail: 'requested client is not recorded in the ownership manifest',
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $recordedClientIds
     * @param list<string>|null $clientFilter
     * @return list<string>
     */
    private function resolveClientIds(array $recordedClientIds, ?array $clientFilter): array
    {
        if ($clientFilter === null) {
            return $recordedClientIds;
        }

        $recorded = array_flip($recordedClientIds);
        $selected = [];
        foreach ($clientFilter as $requested) {
            if (isset($recorded[$requested])) {
                $selected[] = $requested;
            }
        }
        sort($selected);

        return $selected;
    }
}
