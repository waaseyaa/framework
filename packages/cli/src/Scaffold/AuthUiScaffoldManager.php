<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Scaffold;

use Composer\InstalledVersions;

final class AuthUiScaffoldManager
{
    public const string MANIFEST_SCHEMA = 'waaseyaa.scaffold-manifest.v2';

    /** @var array<string, string> source (relative to packages/admin/app/) => destination (relative to app/) */
    public const array FILE_MAP = [
        'pages/login.vue' => 'pages/login.vue',
        'components/auth/LoginForm.vue' => 'components/auth/LoginForm.vue',
        'components/auth/BrandPanel.vue' => 'components/auth/BrandPanel.vue',
        'composables/useAuth.ts' => 'composables/useAuth.ts',
        'assets/auth.css' => 'assets/auth.css',
    ];

    public function __construct(
        private readonly string $projectRoot,
        private readonly CliInstallPathResolverInterface $installPathResolver = new ComposerCliInstallPathResolver(),
    ) {}

    /**
     * @return array{actions: list<array{action: string, path: string, source: string}>, copied: int, skipped: int}
     */
    public function publish(bool $force, bool $dryRun): array
    {
        $context = $this->sourceContext();
        $manifest = $this->loadManifestIfPresent();
        $document = $manifest['document'] ?? $this->emptyManifest();
        $files = $manifest['files'] ?? [];
        $actions = [];
        $copied = 0;
        $skipped = 0;

        foreach (self::FILE_MAP as $source => $destination) {
            $sourcePath = $context['source_base'] . '/' . $source;
            $destinationPath = $this->consumerBase() . '/' . $destination;

            if (!is_file($sourcePath)) {
                $actions[] = ['action' => 'missing', 'path' => $destination, 'source' => $source];
                continue;
            }

            if (is_file($destinationPath) && !$force) {
                $actions[] = ['action' => 'skip', 'path' => $destination, 'source' => $source];
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $actions[] = ['action' => 'copy', 'path' => $destination, 'source' => $source];
                continue;
            }

            $destinationDirectory = dirname($destinationPath);
            if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0o755, true) && !is_dir($destinationDirectory)) {
                throw new \RuntimeException(sprintf('Unable to create auth UI destination directory: %s', $destination));
            }
            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException(sprintf('Unable to copy auth UI file: %s', $destination));
            }

            $digest = $this->digest($destinationPath, 'sha256');
            $files[$destination] = [
                'source' => $source,
                'framework_version' => $context['framework_version'],
                'digest_algorithm' => 'sha256',
                'upstream_digest' => $digest,
                'consumer_digest' => $digest,
            ];
            $actions[] = ['action' => 'copy', 'path' => $destination, 'source' => $source];
            ++$copied;
        }

        if (!$dryRun && $copied > 0) {
            $this->writeManifest($document, $files);
        }

        return ['actions' => $actions, 'copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * @return array{
     *   status: 'ok'|'not-published'|'error',
     *   findings: list<array{state: string, path: string, detail: string}>,
     *   current: int,
     *   legacy: bool,
     *   error: string|null
     * }
     */
    public function inspect(): array
    {
        $context = $this->sourceContext();
        $manifestPath = $this->manifestPath();
        if (!is_file($manifestPath)) {
            if ($this->hasConsumerFiles()) {
                return $this->errorReport('Auth UI scaffold manifest is missing while published auth UI files exist. No files were changed.');
            }

            return [
                'status' => 'not-published',
                'findings' => [],
                'current' => 0,
                'legacy' => false,
                'error' => null,
            ];
        }

        try {
            $manifest = $this->loadManifest();
        } catch (\RuntimeException $exception) {
            return $this->errorReport($exception->getMessage());
        }

        $files = $manifest['files'];
        $currentByDestination = array_flip(self::FILE_MAP);
        $destinations = array_values(array_unique(array_merge(array_keys($currentByDestination), array_keys($files))));
        sort($destinations, SORT_STRING);

        $findings = [];
        $current = 0;
        foreach ($destinations as $destination) {
            $source = $currentByDestination[$destination] ?? null;
            $entry = $files[$destination] ?? null;

            if (!is_string($source)) {
                $findings[] = [
                    'state' => 'removed',
                    'path' => $destination,
                    'detail' => 'the recorded file is no longer in the Framework publishable set',
                ];
                continue;
            }

            $sourcePath = $context['source_base'] . '/' . $source;
            $consumerPath = $this->consumerBase() . '/' . $destination;
            if (!is_array($entry)) {
                $findings[] = [
                    'state' => 'added',
                    'path' => $destination,
                    'detail' => is_file($consumerPath)
                        ? 'Framework publishes this file but the existing consumer file is not recorded'
                        : 'Framework publishes a new file that is not recorded',
                ];
                continue;
            }
            if (!is_file($sourcePath)) {
                $findings[] = [
                    'state' => 'removed',
                    'path' => $destination,
                    'detail' => 'the recorded upstream source is missing',
                ];
                continue;
            }
            if (!is_file($consumerPath)) {
                $findings[] = [
                    'state' => 'removed',
                    'path' => $destination,
                    'detail' => 'the recorded consumer file is missing',
                ];
                continue;
            }

            $algorithm = $entry['digest_algorithm'];
            $upstreamDigest = $this->digest($sourcePath, $algorithm);
            $consumerDigest = $this->digest($consumerPath, $algorithm);
            $sourceIdentityChanged = $entry['source'] !== $source;
            $upstreamChanged = $sourceIdentityChanged || $upstreamDigest !== $entry['upstream_digest'];
            $consumerChanged = $consumerDigest !== $entry['consumer_digest'];

            if ($upstreamChanged && $consumerChanged && $upstreamDigest !== $consumerDigest) {
                $findings[] = [
                    'state' => 'conflict',
                    'path' => $destination,
                    'detail' => 'both upstream and consumer changed since the recorded baseline',
                ];
            } elseif ($upstreamChanged) {
                $findings[] = [
                    'state' => 'changed-upstream',
                    'path' => $destination,
                    'detail' => $consumerChanged
                        ? 'upstream changed and the consumer already matches it; review then accept the baseline'
                        : 'upstream changed while the consumer remains at its recorded baseline',
                ];
            } elseif ($consumerChanged) {
                $findings[] = [
                    'state' => 'changed-consumer',
                    'path' => $destination,
                    'detail' => 'consumer changed while upstream remains at its recorded baseline',
                ];
            } else {
                ++$current;
            }
        }

        return [
            'status' => 'ok',
            'findings' => $findings,
            'current' => $current,
            'legacy' => $manifest['legacy'],
            'error' => null,
        ];
    }

    public function acceptCurrent(): int
    {
        $context = $this->sourceContext();
        $manifest = $this->loadManifestIfPresent();
        $document = $manifest['document'] ?? $this->emptyManifest();
        $files = [];

        foreach (self::FILE_MAP as $source => $destination) {
            $sourcePath = $context['source_base'] . '/' . $source;
            $consumerPath = $this->consumerBase() . '/' . $destination;
            if (!is_file($sourcePath)) {
                throw new \RuntimeException(sprintf('Cannot accept auth UI baselines: upstream source is missing for %s.', $destination));
            }
            if (!is_file($consumerPath)) {
                throw new \RuntimeException(sprintf('Cannot accept auth UI baselines: consumer file is missing for %s.', $destination));
            }

            $files[$destination] = [
                'source' => $source,
                'framework_version' => $context['framework_version'],
                'digest_algorithm' => 'sha256',
                'upstream_digest' => $this->digest($sourcePath, 'sha256'),
                'consumer_digest' => $this->digest($consumerPath, 'sha256'),
            ];
        }

        $this->writeManifest($document, $files);

        return count($files);
    }

    /**
     * @return array{source_base: string, framework_version: string}
     */
    private function sourceContext(): array
    {
        return $this->sourceContextFromCandidates($this->sourceCandidates());
    }

    /**
     * @param list<array{source_base: string, version_roots: list<string>}> $candidates
     * @return array{source_base: string, framework_version: string}
     */
    private function sourceContextFromCandidates(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate['source_base'])) {
                continue;
            }

            $version = $this->resolveFrameworkVersion($candidate['version_roots']);
            if ($version === '') {
                throw new \RuntimeException('Unable to identify the Framework version that owns the auth UI sources.');
            }

            return [
                'source_base' => $candidate['source_base'],
                'framework_version' => $version,
            ];
        }

        throw new \RuntimeException(
            'Framework auth UI sources were not found in the project (packages/admin/app), an installed '
            . 'waaseyaa/framework package (vendor/waaseyaa/framework/packages/admin/app), the installed '
            . 'waaseyaa/cli package (resources/auth-ui), or the loaded waaseyaa/cli package\'s own resources/auth-ui.',
        );
    }

    /**
     * Ordered source roots for scaffold:auth (#2833).
     *
     * (a) and (b) are project-owned and metapackage-aggregate lookups.
     * Direct-package consumers omit waaseyaa/framework (ADR-004), so (c)
     * resolves the canonical package-owned mirror shipped inside the
     * installed waaseyaa/cli package itself
     * (packages/cli/resources/auth-ui — kept byte-identical to
     * packages/admin/app by bin/sync-cli-auth-ui-resources, see
     * CliAuthUiResourceParityTest). (d) is a loaded-package-local fallback,
     * anchored on this class's own file location, for runtimes where
     * Composer\InstalledVersions does not register the package — the same
     * convention as Waaseyaa\Bimaaji\Install\PackagedSkillResources. No
     * candidate here guesses at a sibling directory outside the package it
     * resolves from (see FW-2833-AUTH-SCAFFOLD-SOURCES-01.md, decision 3).
     *
     * @return list<array{source_base: string, version_roots: list<string>}>
     */
    private function sourceCandidates(): array
    {
        $candidates = [
            [
                'source_base' => $this->projectRoot . '/packages/admin/app',
                'version_roots' => [$this->projectRoot],
            ],
            [
                'source_base' => $this->projectRoot . '/vendor/waaseyaa/framework/packages/admin/app',
                'version_roots' => [$this->projectRoot . '/vendor/waaseyaa/framework'],
            ],
        ];

        $cliRoot = $this->installPathResolver->resolve();
        if ($cliRoot !== null && $cliRoot !== '') {
            $candidates[] = [
                'source_base' => rtrim($cliRoot, '/\\') . '/resources/auth-ui',
                'version_roots' => [$cliRoot],
            ];
        }

        $loadedCliRoot = dirname(__DIR__, 2);
        $candidates[] = [
            'source_base' => $loadedCliRoot . '/resources/auth-ui',
            'version_roots' => [$loadedCliRoot],
        ];

        return $candidates;
    }

    /**
     * @param list<string> $versionRoots
     */
    private function resolveFrameworkVersion(array $versionRoots): string
    {
        foreach ($versionRoots as $root) {
            $versionPath = rtrim($root, '/\\') . '/VERSION';
            if (!is_file($versionPath)) {
                continue;
            }
            $version = trim((string) file_get_contents($versionPath));
            if ($version !== '') {
                return $version;
            }
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('waaseyaa/framework')) {
            $version = InstalledVersions::getPrettyVersion('waaseyaa/framework') ?? '';
            if ($version !== '') {
                return $version;
            }
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('waaseyaa/cli')) {
            return InstalledVersions::getPrettyVersion('waaseyaa/cli') ?? '';
        }

        return '';
    }

    private function hasConsumerFiles(): bool
    {
        foreach (self::FILE_MAP as $destination) {
            if (is_file($this->consumerBase() . '/' . $destination)) {
                return true;
            }
        }

        return false;
    }

    private function consumerBase(): string
    {
        return rtrim($this->projectRoot, '/\\') . '/app';
    }

    private function manifestPath(): string
    {
        return $this->consumerBase() . '/.waaseyaa/scaffold-manifest.json';
    }

    /**
     * @return array{document: array<string, mixed>, files: array<string, array<string, string>>, legacy: bool}|null
     */
    private function loadManifestIfPresent(): ?array
    {
        return is_file($this->manifestPath()) ? $this->loadManifest() : null;
    }

    /**
     * @return array{document: array<string, mixed>, files: array<string, array<string, string>>, legacy: bool}
     */
    private function loadManifest(): array
    {
        $raw = file_get_contents($this->manifestPath());
        if (!is_string($raw)) {
            throw new \RuntimeException('Auth UI scaffold manifest could not be read. No files were changed.');
        }
        try {
            $objectShape = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Auth UI scaffold manifest is malformed JSON. No files were changed.', previous: $exception);
        }
        if (!$objectShape instanceof \stdClass || !is_array($decoded)) {
            throw new \RuntimeException('Auth UI scaffold manifest is malformed: expected an object. No files were changed.');
        }

        if (($decoded['schema'] ?? null) === self::MANIFEST_SCHEMA) {
            $files = $decoded['scaffolds']['auth-ui']['files'] ?? null;
            if (!is_array($files)) {
                throw new \RuntimeException('Auth UI scaffold manifest is malformed: auth-ui files are missing. No files were changed.');
            }

            $validated = [];
            foreach ($files as $destination => $entry) {
                if (!is_string($destination) || !$this->isSafeRelativePath($destination) || !is_array($entry)) {
                    throw new \RuntimeException('Auth UI scaffold manifest is malformed: invalid file identity. No files were changed.');
                }
                $source = $entry['source'] ?? null;
                $version = $entry['framework_version'] ?? null;
                $algorithm = $entry['digest_algorithm'] ?? null;
                $upstreamDigest = $entry['upstream_digest'] ?? null;
                $consumerDigest = $entry['consumer_digest'] ?? null;
                $expectedLength = $algorithm === 'sha256' ? 64 : ($algorithm === 'md5' ? 32 : 0);
                if (!is_string($source) || !$this->isSafeRelativePath($source)
                    || !is_string($version) || $version === ''
                    || !is_string($algorithm) || $expectedLength === 0
                    || !is_string($upstreamDigest) || preg_match('/^[a-f0-9]{' . $expectedLength . '}$/D', $upstreamDigest) !== 1
                    || !is_string($consumerDigest) || preg_match('/^[a-f0-9]{' . $expectedLength . '}$/D', $consumerDigest) !== 1
                ) {
                    throw new \RuntimeException(sprintf('Auth UI scaffold manifest is malformed at %s. No files were changed.', $destination));
                }
                $validated[$destination] = [
                    'source' => $source,
                    'framework_version' => $version,
                    'digest_algorithm' => $algorithm,
                    'upstream_digest' => $upstreamDigest,
                    'consumer_digest' => $consumerDigest,
                ];
            }

            ksort($validated, SORT_STRING);

            return ['document' => $decoded, 'files' => $validated, 'legacy' => false];
        }

        $legacy = [];
        foreach ($decoded as $destination => $digest) {
            if (!is_string($destination) || !$this->isSafeRelativePath($destination)
                || !is_string($digest) || preg_match('/^[a-f0-9]{32}$/D', $digest) !== 1
            ) {
                throw new \RuntimeException('Auth UI scaffold manifest is malformed or has an unsupported schema. No files were changed.');
            }
            $source = array_search($destination, self::FILE_MAP, true);
            $legacy[$destination] = [
                'source' => is_string($source) ? $source : $destination,
                'framework_version' => 'legacy-unknown',
                'digest_algorithm' => 'md5',
                'upstream_digest' => $digest,
                'consumer_digest' => $digest,
            ];
        }
        ksort($legacy, SORT_STRING);

        return ['document' => $this->emptyManifest(), 'files' => $legacy, 'legacy' => true];
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, string>> $files
     */
    private function writeManifest(array $document, array $files): void
    {
        ksort($files, SORT_STRING);
        $document['schema'] = self::MANIFEST_SCHEMA;
        if (!isset($document['scaffolds']) || !is_array($document['scaffolds'])) {
            $document['scaffolds'] = [];
        }
        $document['scaffolds']['auth-ui'] = ['files' => $files];

        $manifestDirectory = dirname($this->manifestPath());
        if (!is_dir($manifestDirectory) && !mkdir($manifestDirectory, 0o755, true) && !is_dir($manifestDirectory)) {
            throw new \RuntimeException('Unable to create the auth UI scaffold manifest directory.');
        }
        $temporaryPath = tempnam($manifestDirectory, '.scaffold-manifest.');
        if ($temporaryPath === false) {
            throw new \RuntimeException('Unable to allocate a temporary auth UI scaffold manifest.');
        }
        $bytes = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporaryPath, $bytes) === false || !rename($temporaryPath, $this->manifestPath())) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to write the auth UI scaffold manifest atomically.');
        }
    }

    /** @return array{schema: string, scaffolds: array<string, mixed>} */
    private function emptyManifest(): array
    {
        return ['schema' => self::MANIFEST_SCHEMA, 'scaffolds' => []];
    }

    private function digest(string $path, string $algorithm): string
    {
        $digest = hash_file($algorithm, $path);
        if (!is_string($digest)) {
            throw new \RuntimeException(sprintf('Unable to hash auth UI file: %s', basename($path)));
        }

        return $digest;
    }

    private function isSafeRelativePath(string $path): bool
    {
        $segments = preg_split('#[\\\\/]#', $path);

        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_starts_with($path, '\\')
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1
            && !in_array('..', $segments === false ? [] : $segments, true);
    }

    /**
     * @return array{
     *   status: 'error',
     *   findings: list<array{state: string, path: string, detail: string}>,
     *   current: int,
     *   legacy: bool,
     *   error: string
     * }
     */
    private function errorReport(string $message): array
    {
        return [
            'status' => 'error',
            'findings' => [],
            'current' => 0,
            'legacy' => false,
            'error' => $message,
        ];
    }
}
