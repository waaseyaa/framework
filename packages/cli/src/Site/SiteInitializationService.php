<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\Exception\SiteInitializationLockedException;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Generation\ArtifactApplyResult;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\ArtifactStatus;
use Waaseyaa\SiteContract\Generation\ChangeOutcome;
use Waaseyaa\SiteContract\Generation\ChangeReceipt;
use Waaseyaa\SiteContract\Generation\EvaluatedArtifactPlan;
use Waaseyaa\SiteContract\Generation\Exception\GenerationErrorCode;
use Waaseyaa\SiteContract\Generation\Exception\GenerationRefusalException;
use Waaseyaa\SiteContract\Generation\Exception\GenerationViolation;
use Waaseyaa\SiteContract\Generation\GeneratedArtifact;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\ObservedTargetMode;
use Waaseyaa\SiteContract\Generation\ObservedTargetState;
use Waaseyaa\SiteContract\Generation\ProjectStateIdentity;
use Waaseyaa\SiteContract\Generation\ProjectStateTarget;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

/** @api */
final class SiteInitializationService
{
    /**
     * The sole machine-readable declaration of this authority's contract
     * version (ADR-025 D-14.9). Receipts, tests, fixtures and documentation
     * read it; none of them restate its value.
     */
    public const int CONTRACT_VERSION = 1;

    private const string METADATA = '.waaseyaa/generated.json';
    private const string JOURNAL = '.waaseyaa/site-init.transaction.json';
    private const string LOCK = '.waaseyaa/site-init.lock';

    private readonly string $projectRoot;

    private readonly SiteHostPlatform $platform;

    public function __construct(
        string $projectRoot,
        private readonly ?\Closure $faultInjector = null,
        ?SiteHostPlatform $platform = null,
    ) {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root) || is_link($projectRoot)) {
            throw new \InvalidArgumentException('The site project root must be an existing, non-symlink directory.');
        }
        $this->projectRoot = rtrim($root, DIRECTORY_SEPARATOR);
        $this->platform = $platform ?? SiteHostPlatform::host();
    }

    /** @param null|\Closure(list<string>): bool $authorize */
    public function initialize(GeneratedSite $site, bool $dryRun = false, ?\Closure $authorize = null): SiteInitializationResult
    {
        if ($dryRun) {
            if (is_file($this->absolute(self::JOURNAL))) {
                throw new \RuntimeException('Site initialization recovery or committed cleanup requires a non-dry run before a new plan can be computed.');
            }
            $prepared = $this->prepare($site);

            return new SiteInitializationResult(array_keys($prepared), true);
        }

        $controlDirectory = $this->absolute('.waaseyaa');
        if (!is_file($this->absolute(self::JOURNAL))) {
            // Refuse deterministic collisions before creating lock/control state.
            // The same checks run again under the lock before publication.
            $this->prepare($site);
        }
        if (!is_dir($controlDirectory) && !mkdir($controlDirectory, 0o700, true) && !is_dir($controlDirectory)) {
            throw new \RuntimeException('Unable to create the site initialization control directory.');
        }
        if (is_link($controlDirectory)) {
            throw new SiteInitializationCollisionException('The .waaseyaa control directory must not be a symbolic link.');
        }
        $controlIgnore = $site->artifacts['.waaseyaa/.gitignore'] ?? null;
        if (!$controlIgnore instanceof GeneratedArtifact) {
            throw new \InvalidArgumentException('Generated site control-ignore authority is required.');
        }
        $controlIgnorePath = $this->absolute($controlIgnore->path);
        if (!is_file($controlIgnorePath)) {
            $this->writeDurably($controlIgnorePath, $controlIgnore->content, $controlIgnore->mode);
        } elseif (!hash_equals(hash('sha256', $controlIgnore->content), $this->digestFile($controlIgnorePath))) {
            throw new SiteInitializationCollisionException('The site initialization control ignore file was substituted.');
        }

        $lockPath = $this->absolute(self::LOCK);
        if (file_exists($lockPath) || is_link($lockPath)) {
            $this->assertRegularOwnedFile($lockPath, self::LOCK);
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Unable to open the site initialization lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new SiteInitializationLockedException('Another site initialization transaction owns this project.');
        }

        $recovered = false;
        try {
            $recovered = $this->recoverIfRequired();
            $prepared = $this->prepare($site);
            if ($prepared === []) {
                return new SiteInitializationResult([], recoveredInterruptedTransaction: $recovered);
            }
            $changedPaths = array_keys($prepared);
            if ($authorize !== null && !$authorize($changedPaths)) {
                return new SiteInitializationResult($changedPaths, recoveredInterruptedTransaction: $recovered, cancelled: true);
            }
            $cleanupPending = $this->publish($prepared);

            return new SiteInitializationResult($changedPaths, recoveredInterruptedTransaction: $recovered, cleanupPending: $cleanupPending);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, GeneratedArtifact> */
    private function prepare(GeneratedSite $site): array
    {
        $metadataPath = $this->absolute(self::METADATA);
        $hasMetadata = is_file($metadataPath);
        $prior = $hasMetadata ? $this->readMetadata($metadataPath) : null;
        $priorRows = [];
        if ($prior !== null) {
            $priorManifestPath = $this->absolute('.waaseyaa/site.yaml');
            if (!is_file($priorManifestPath)) {
                throw new SiteInitializationCollisionException('Generated ownership metadata exists without its manifest authority.');
            }
            try {
                $priorManifest = new SiteManifestParser()->parse((string) file_get_contents($priorManifestPath), '.waaseyaa/site.yaml');
            } catch (\Throwable $exception) {
                throw new SiteInitializationCollisionException('The previously generated site authority is not reproducible.', previous: $exception);
            }
            if (!hash_equals($priorManifest->digest, $prior['manifest_digest']) || $priorManifest->generatorVersion !== $prior['generator_version']) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not bind the current manifest authority.');
            }
            if ($prior['generator_version'] !== $site->generatorVersion) {
                throw new SiteInitializationCollisionException(sprintf(
                    'Generated artifact migration from version %d to %d is required before regeneration.',
                    $prior['generator_version'],
                    $site->generatorVersion,
                ));
            }
            foreach ($prior['artifacts'] as $row) {
                if (isset($priorRows[$row['path']])) {
                    throw new SiteInitializationCollisionException("Generated ownership metadata repeats {$row['path']}.");
                }
                $priorRows[$row['path']] = $row;
            }
            $expectedOwnedPaths = array_values(array_filter(array_keys($site->artifacts), static fn(string $path): bool => $path !== self::METADATA));
            $recordedPaths = array_keys($priorRows);
            sort($expectedOwnedPaths, SORT_STRING);
            sort($recordedPaths, SORT_STRING);
            if ($expectedOwnedPaths !== $recordedPaths) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not match this generator version.');
            }
            if (hash_equals($prior['manifest_digest'], $site->manifestDigest)) {
                foreach ($site->artifacts as $path => $artifact) {
                    if ($path === self::METADATA) {
                        continue;
                    }
                    if (!hash_equals($priorRows[$path]['managed_sha256'], $artifact->managedDigest())) {
                        // #2644: this fires when the framework's renderer has
                        // changed but the manifest still binds the previous
                        // dependency lock — the manifest digest is unchanged, so
                        // regeneration cannot tell an upgrade from a
                        // substitution. Naming only a migration that does not
                        // exist as a command left the operator with no move.
                        // Rebinding the lock is the sanctioned one: it changes
                        // the manifest digest, which is precisely the signal
                        // that this is a reviewed upgrade.
                        throw new SiteInitializationCollisionException(sprintf(
                            'Generated artifact bytes changed without a generator-version migration: %s. '
                            . 'If this followed a framework upgrade, rebind framework.observed_lock_sha256 in '
                            . '.waaseyaa/site.yaml to the sha256 of the current composer.lock and re-run site:init.',
                            $path,
                        ));
                    }
                }
            }
        }

        return $this->evaluateTargets($site->artifacts, $hasMetadata, $priorRows)['prepared'];
    }

    /**
     * Evaluate one immutable plan against this project (ADR-025 D-6.2).
     *
     * Observes the project and writes nothing -- not even the control directory -- because
     * a preview that mutates is not a preview. It enters the same target
     * evaluator dry-run and apply enter, so no check can differ between them.
     *
     * No handler reaches this dormant boundary. The unit reader and root
     * binding are complete here; public apply and its stale-plan check remain
     * unwired until slice 8. Collisions still throw rather than becoming
     * refused-status return values.
     */
    public function evaluate(ArtifactPlan $plan): EvaluatedArtifactPlan
    {
        if (is_file($this->absolute(self::JOURNAL))) {
            throw new SiteInitializationCollisionException('An interrupted site initialization must be recovered before a plan is evaluated.');
        }
        return $this->prepareUnitPlan($plan)['evaluation'];
    }

    /**
     * The closed compiler admission list. No seeded compiler has migrated yet.
     * Persisted provenance is readable independently of new-plan eligibility.
     *
     * @var list<string>
     */
    private const array SEEDED_COMPILERS = [];

    /** @internal The shared validated reader for the dormant unit-aware doctor.
     * @return array<string, mixed>
     */
    public function readUnitMetadata(): array
    {
        $path = $this->absolute(self::METADATA);
        $this->assertSafeTarget(self::METADATA);
        $this->assertRegularOwnedFile($path, self::METADATA);

        return $this->readMetadata($path, true);
    }

    /**
     * Prepare the complete ownership transition without staging a byte.
     * Handler activation, stale-plan checking and public apply are slice 8.
     *
     * @return array{prepared: array<string, GeneratedArtifact>, retirements: array<string, array<string, mixed>>, composerMerge: array{content: string, mode: int, before_sha256: string}|null, evaluation: EvaluatedArtifactPlan}
     */
    private function prepareUnitPlan(ArtifactPlan $plan): array
    {
        if ($plan->schemaEffects !== [] || $plan->configEffects !== []) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'Reserved effects are not active.');
        }
        if ($plan->setEvolution !== ArtifactSetEvolution::Frozen) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'Additive evolution is not active.');
        }
        if (in_array('site', $plan->retires, true)) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root generation unit is not retirable.');
        }
        if ($plan->unitId === 'site' && ($plan->generatorFqcn !== SiteArtifactRenderer::class || $plan->disposition !== GenerationUnitDisposition::Managed)) {
            $this->unitRefusal(GenerationErrorCode::MaliciousIdentifier, 'The site unit is reserved for the managed root compiler.');
        }
        $metadataPath = $this->absolute(self::METADATA);
        $hasMetadata = file_exists($metadataPath) || is_link($metadataPath);
        $prior = $hasMetadata ? $this->readUnitMetadata() : null;
        if ($prior === null && $plan->unitId !== 'site') {
            $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A non-root unit requires an initialized site.');
        }
        if ($prior !== null) {
            $this->assertSafeTarget('.waaseyaa/site.yaml');
            $manifestPath = $this->absolute('.waaseyaa/site.yaml');
            if (!file_exists($manifestPath) && !is_link($manifestPath)) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Generated ownership metadata exists without its manifest authority.');
            }
            $this->assertRegularOwnedFile($manifestPath, '.waaseyaa/site.yaml');
            try {
                $manifest = new SiteManifestParser()->parse((string) file_get_contents($manifestPath), '.waaseyaa/site.yaml');
            } catch (\Throwable $exception) {
                throw new SiteInitializationCollisionException('The previously generated site authority is not reproducible.', previous: $exception);
            }
            if (!hash_equals($manifest->digest, $prior['manifest_digest']) || $manifest->generatorVersion !== $prior['generator_version']) {
                throw new SiteInitializationCollisionException('Generated ownership metadata does not bind the current manifest authority.');
            }
        }
        $units = [];
        foreach ($prior['units'] ?? [] as $unit) {
            $units[$unit['id']] = $unit;
        }
        $existing = $plan->unitId === 'site'
            ? ($prior === null ? null : ['generator' => ['fqcn' => SiteArtifactRenderer::class, 'version' => $prior['generator_version']], 'disposition' => 'managed', 'input_digest' => $prior['manifest_digest']])
            : ($units[$plan->unitId] ?? null);
        if ($existing === null && $plan->unitId !== 'site' && $plan->artifacts === [] && $plan->registrations === []) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'A new non-root unit must own state.');
        }
        if ($existing !== null && ($existing['generator']['fqcn'] !== $plan->generatorFqcn || $existing['generator']['version'] !== $plan->generatorVersion || $existing['disposition'] !== $plan->disposition->value)) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A recorded unit cannot change compiler identity or disposition.');
        }
        // @phpstan-ignore function.impossibleType (the reviewed compiler allowlist is intentionally empty before migrations)
        if ($existing === null && $plan->disposition === GenerationUnitDisposition::Seeded && !in_array($plan->generatorFqcn, self::SEEDED_COMPILERS, true)) {
            $this->unitRefusal(GenerationErrorCode::UnsupportedDeclaration, 'The compiler is not permitted to create seeded units.');
        }
        $composerState = $this->readComposerProviderState();
        $registrationEffects = $this->reconcileRegistrations($plan, $prior['registrations'] ?? [], $existing, $composerState);
        /** @var array<string, array<string, mixed>> $priorRows */
        $priorRows = [];
        /** @var array<string, array<string, mixed>> $suppliedRows */
        $suppliedRows = [];
        /** @var array<string, array<string, mixed>> $observedRows */
        $observedRows = [];
        foreach ($prior['artifacts'] ?? [] as $row) {
            $priorRows[$row['path']] = $row;
            $owner = $row['unit'] ?? 'site';
            if ($owner === $plan->unitId) {
                $suppliedRows[$row['path']] = $row;
            }
            if ($owner === $plan->unitId || in_array($owner, $plan->retires, true)) {
                $observedRows[$row['path']] = $row;
            }
        }
        $artifacts = [];
        foreach ($plan->artifacts as $artifact) {
            $this->assertUnitOwnershipPath($artifact->path);
            if ($artifact->path === self::METADATA) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A compiler cannot own the composed metadata.', $artifact->path);
            }
            if (isset($priorRows[$artifact->path]) && ($priorRows[$artifact->path]['unit'] ?? 'site') !== $plan->unitId) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'The path belongs to another generation unit.', $artifact->path);
            }
            $artifacts[$artifact->path] = $artifact;
        }
        $adds = $existing === null ? [] : array_values(array_diff(array_keys($artifacts), array_keys($suppliedRows)));
        $drops = $existing === null ? [] : array_values(array_diff(array_keys($suppliedRows), array_keys($artifacts)));
        if ($drops !== []) {
            $this->unitRefusal(GenerationErrorCode::UndeclaredUnitRetirement, 'A supplied unit cannot drop recorded paths.', (string) $drops[0]);
        }
        if ($adds !== []) {
            throw new SiteInitializationCollisionException('Generated ownership metadata does not match this generator version.');
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Managed && hash_equals($existing['input_digest'], $plan->inputDigest)) {
            foreach ($artifacts as $path => $artifact) {
                if (!hash_equals($suppliedRows[$path]['managed_sha256'], $artifact->managedDigest())) {
                    $this->unitRefusal(GenerationErrorCode::AmbiguousExtensionRegion, 'Generated bytes changed without a changed input identity.', $path);
                }
            }
        }
        if ($plan->unitId === 'site') {
            $rootManifest = $artifacts['.waaseyaa/site.yaml'] ?? null;
            if (!$rootManifest instanceof GeneratedArtifact) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A root plan requires its manifest authority.');
            }
            $renderedManifest = new SiteManifestParser()->parse($rootManifest->content, '.waaseyaa/site.yaml');
            if (!hash_equals($renderedManifest->digest, $plan->inputDigest) || $renderedManifest->generatorVersion !== $plan->generatorVersion) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root plan does not bind its manifest authority.');
            }
        }
        $retirements = [];
        foreach ($observedRows as $path => $row) {
            $path = (string) $path;
            if (!in_array($row['unit'] ?? 'site', $plan->retires, true)) {
                continue;
            }
            $this->assertSafeTarget($path);
            $absolute = $this->absolute($path);
            if (!file_exists($absolute) && !is_link($absolute)) {
                $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'A retired artifact is missing.', $path);
            }
            $this->assertRegularOwnedFile($absolute, $path);
            try {
                $artifact = new GeneratedArtifact($path, (string) file_get_contents($absolute), intval($row['mode'], 8), $row['extension_region'] ?? null);
                $matches = hash_equals($row['managed_sha256'], $artifact->managedDigest());
            } catch (\InvalidArgumentException) {
                $matches = false;
            }
            if (!$matches || !$this->modeMatches($absolute, intval($row['mode'], 8))) {
                $this->unitRefusal(GenerationErrorCode::AmbiguousExtensionRegion, 'Retirement refuses modified generated content or mode.', $path);
            }
            $retirements[$path] = $row;
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Seeded) {
            $targets = ['prepared' => [], 'status' => array_fill_keys(array_keys($artifacts), ArtifactStatus::Unchanged)];
        } else {
            // New, unowned files must retain the same collision polarity as a
            // pristine publish; an initialized project's existence is no grant.
            foreach ($artifacts as $path => $artifact) {
                if (!isset($priorRows[$path]) && (file_exists($this->absolute($path)) || is_link($this->absolute($path)))) {
                    $this->evaluateTargets([$path => $artifact], false, []);
                }
            }
            $targets = $this->evaluateTargets($artifacts, $hasMetadata, $suppliedRows);
        }
        $document = $prior ?? [
            'schema' => 'waaseyaa.generated', 'version' => 1,
            'generator_version' => $plan->generatorVersion, 'manifest_digest' => $plan->inputDigest,
            'artifacts' => [],
        ];
        $rows = [];
        foreach ($priorRows as $path => $row) {
            $owner = $row['unit'] ?? 'site';
            if (!in_array($owner, $plan->retires, true) && ($owner !== $plan->unitId || $plan->disposition === GenerationUnitDisposition::Seeded)) {
                $rows[$path] = $row;
            }
        }
        if ($existing === null || $plan->disposition === GenerationUnitDisposition::Managed) {
            foreach ($artifacts as $path => $artifact) {
                $row = ['path' => $path, 'mode' => sprintf('%04o', $artifact->mode), 'managed_sha256' => $artifact->managedDigest()];
                if ($artifact->extensionRegion !== null) {
                    $row['extension_region'] = $artifact->extensionRegion;
                }
                if ($plan->unitId !== 'site') {
                    $row['unit'] = $plan->unitId;
                }
                $rows[$path] = $row;
            }
            if ($plan->unitId === 'site') {
                $document['generator_version'] = $plan->generatorVersion;
                $document['manifest_digest'] = $plan->inputDigest;
            } else {
                $units[$plan->unitId] = ['id' => $plan->unitId, 'disposition' => $plan->disposition->value, 'generator' => ['fqcn' => $plan->generatorFqcn, 'version' => $plan->generatorVersion], 'input_digest' => $plan->inputDigest];
            }
        }
        foreach ($plan->retires as $retired) {
            unset($units[$retired]);
        }
        $registrations = $registrationEffects['registrations'];
        if ($plan->unitId !== 'site' && $plan->disposition === GenerationUnitDisposition::Managed) {
            $ownsState = false;
            foreach ([...array_values($rows), ...$registrations] as $row) {
                if (($row['unit'] ?? 'site') === $plan->unitId) {
                    $ownsState = true;
                }
            }
            if (!$ownsState) {
                unset($units[$plan->unitId]);
            }
        }
        unset($document['registrations']);
        if ($registrations !== []) {
            $document['registrations'] = $registrations;
        }
        ksort($rows, SORT_STRING);
        ksort($units, SORT_STRING);
        $document['artifacts'] = array_values($rows);
        unset($document['units']);
        if ($units !== []) {
            $document['units'] = array_values($units);
        }
        // Re-derive every supplied managed row from the actual admitted artifact
        // (including preserved extension bytes), before metadata can be staged.
        foreach ($artifacts as $path => $artifact) {
            if ($plan->disposition === GenerationUnitDisposition::Managed) {
                $admitted = $targets['prepared'][$path] ?? $artifact;
                if (!hash_equals($rows[$path]['managed_sha256'], $admitted->managedDigest())) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Composed ownership does not certify the admitted artifact.', $path);
                }
            }
        }
        $metadata = new GeneratedArtifact(self::METADATA, CanonicalJson::encode($document) . "\n");
        $metadataTarget = $this->evaluateTargets([self::METADATA => $metadata], $hasMetadata, []);
        $prepared = $targets['prepared'] + $metadataTarget['prepared'];
        ksort($prepared, SORT_STRING);

        return [
            'prepared' => $prepared,
            'retirements' => $retirements,
            'composerMerge' => $registrationEffects['composerMerge'],
            'evaluation' => new EvaluatedArtifactPlan($plan, $this->captureProjectState($artifacts, $observedRows, $composerState['sha256']), $targets['status'], $adds, $drops),
        ];
    }

    /**
     * @internal Shared Composer observation for the dormant engine and doctor.
     * @return array{exists: bool, raw: ?string, sha256: string, mode: ?int, providers: list<string>, spans: array<string, mixed>}
     */
    public function readComposerProviderState(): array
    {
        $this->assertSafeTarget('composer.json');
        $path = $this->absolute('composer.json');
        if (!file_exists($path) && !is_link($path)) {
            return ['exists' => false, 'raw' => null, 'sha256' => ProjectStateIdentity::ABSENT_DIGEST, 'mode' => null, 'providers' => [], 'spans' => []];
        }
        $this->assertRegularOwnedFile($path, 'composer.json');
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest cannot be read.', 'composer.json');
        }
        $this->injectFault('after-composer-read', -1, 'composer.json');
        try {
            $decoded = json_decode($raw, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest must be valid JSON.', 'composer.json');
        }
        if (!$decoded instanceof \stdClass) {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer manifest must be a JSON object.', 'composer.json');
        }
        // Decode validates syntax; spans preserve foreign number/escape lexemes
        // and object/list identity. Only the targeted ancestor keys are unique.
        $offset = 0;
        $root = $this->composerJsonSpan($raw, $offset);
        $extra = $this->composerObjectMember($root, 'extra');
        $waaseyaa = $extra === null ? null : $this->composerObjectMember($extra, 'waaseyaa');
        $providerSpan = $waaseyaa === null ? null : $this->composerObjectMember($waaseyaa, 'providers');
        $providers = [];
        if ($providerSpan !== null) {
            if ($providerSpan['kind'] !== 'array') {
                $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer providers must be a list.', 'composer.json');
            }
            foreach ($providerSpan['items'] as $item) {
                $provider = json_decode(substr($raw, $item['start'], (int) ($item['end'] - $item['start'])), true, flags: JSON_THROW_ON_ERROR);
                if (!is_string($provider) || $provider === '' || in_array($provider, $providers, true)) {
                    $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer providers must be unique nonempty strings.', 'composer.json');
                }
                $providers[] = $provider;
            }
        }

        return [
            'exists' => true, 'raw' => $raw, 'sha256' => hash('sha256', $raw),
            'mode' => $this->platform->enforcesPermissionBits() ? fileperms($path) & 0o777 : 0o644,
            'providers' => $providers,
            'spans' => ['root' => $root, 'extra' => $extra, 'waaseyaa' => $waaseyaa, 'providers' => $providerSpan],
        ];
    }

    /** @return array<string, mixed> */
    private function composerJsonSpan(string $raw, int &$offset): array
    {
        $offset += strspn($raw, " \t\r\n", $offset);
        $start = $offset;
        $token = $raw[$offset++];
        if ($token === '"') {
            while ($raw[$offset] !== '"') {
                $offset += $raw[$offset] === '\\' ? 2 : 1;
            }
            ++$offset;

            return ['start' => $start, 'end' => $offset, 'kind' => 'string'];
        }
        if ($token !== '{' && $token !== '[') {
            while ($offset < strlen($raw) && !str_contains(" \t\r\n,]}", $raw[$offset])) {
                ++$offset;
            }

            return ['start' => $start, 'end' => $offset, 'kind' => 'scalar'];
        }
        $object = $token === '{';
        $closing = $object ? '}' : ']';
        $members = [];
        $items = [];
        $offset += strspn($raw, " \t\r\n", $offset);
        while ($raw[$offset] !== $closing) {
            if ($object) {
                $key = $this->composerJsonSpan($raw, $offset);
                $offset += strspn($raw, " \t\r\n", $offset);
                ++$offset; // The colon is already JSON-validated.
                $value = $this->composerJsonSpan($raw, $offset);
                $members[] = ['key' => json_decode(substr($raw, $key['start'], (int) ($key['end'] - $key['start'])), true, flags: JSON_THROW_ON_ERROR), 'key_start' => $key['start'], 'value' => $value];
            } else {
                $items[] = $this->composerJsonSpan($raw, $offset);
            }
            $offset += strspn($raw, " \t\r\n", $offset);
            if ($raw[$offset] === ',') {
                ++$offset;
                $offset += strspn($raw, " \t\r\n", $offset);
            }
        }
        ++$offset;

        return ['start' => $start, 'end' => $offset, 'kind' => $object ? 'object' : 'array', 'members' => $members, 'items' => $items];
    }

    /** @param array<string, mixed> $object @return array<string, mixed>|null */
    private function composerObjectMember(array $object, string $key): ?array
    {
        if ($object['kind'] !== 'object') {
            $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer provider ancestors must be JSON objects.', 'composer.json');
        }
        $found = null;
        foreach ($object['members'] as $member) {
            if ($member['key'] === $key) {
                if ($found !== null) {
                    $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Composer provider ancestors must not contain duplicate targeted keys.', 'composer.json');
                }
                $found = $member['value'];
            }
        }

        return $found;
    }

    private function validateRegistrationRosterShape(mixed $roster): void
    {
        if (!is_array($roster) || !array_is_list($roster) || $roster === []) {
            $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A present registration roster must be a nonempty list.');
        }
        foreach ($roster as $row) {
            if (!is_array($row)) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row must be an object.');
            }
            foreach ($row as $key => $value) {
                if (!in_array($key, ['fqcn', 'group', 'unit'], true) || !is_string($value) || $value === '') {
                    $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row has invalid members.');
                }
            }
            if (!isset($row['fqcn'])) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'A registration row requires an FQCN.');
            }
        }
    }

    /** @param list<array<string, string>> $roster @param array<string, bool> $units */
    private function validateRegistrationRosterOwnership(array $roster, array $units): void
    {
        $seen = [];
        foreach ($roster as $row) {
            if (isset($seen[$row['fqcn']]) || (isset($row['unit']) && !isset($units[$row['unit']]))) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'Registration ownership is duplicated or names an unknown unit.');
            }
            $seen[$row['fqcn']] = true;
        }
        $previous = null;
        foreach ($roster as $row) {
            if ($previous !== null && strcmp($previous, $row['fqcn']) >= 0) {
                $this->unitRefusal(GenerationErrorCode::InvalidRegistrationRoster, 'Registration rows must be in canonical FQCN order.');
            }
            $previous = $row['fqcn'];
        }
    }

    /**
     * @param list<array<string, string>> $priorRoster
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed> $composer
     * @return array{registrations: list<array<string, string>>, composerMerge: array{content: string, mode: int, before_sha256: string}|null}
     */
    private function reconcileRegistrations(ArtifactPlan $plan, array $priorRoster, ?array $existing, array $composer): array
    {
        $prior = [];
        $supplied = [];
        foreach ($priorRoster as $row) {
            $prior[$row['fqcn']] = $row;
            if (($row['unit'] ?? 'site') === $plan->unitId) {
                $entry = $row;
                unset($entry['unit']);
                $supplied[] = $entry;
            }
        }
        $declared = array_map(static fn($registration): array => $registration->toArray(), $plan->registrations);
        foreach ($declared as $row) {
            if (isset($prior[$row['fqcn']]) && ($prior[$row['fqcn']]['unit'] ?? 'site') !== $plan->unitId) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'The provider belongs to another generation unit.');
            }
            if (!isset($prior[$row['fqcn']]) && in_array($row['fqcn'], $composer['providers'], true)) {
                $this->unitRefusal(GenerationErrorCode::RegistrationOwnershipConflict, 'The provider is application-owned. Keep its manual registration or remove it deliberately before requesting generation; implicit adoption is not supported.');
            }
        }
        if ($existing !== null && $plan->disposition === GenerationUnitDisposition::Seeded && $supplied !== $declared) {
            $this->unitRefusal(GenerationErrorCode::SeededRegistrationRedeclared, 'A seeded registration declaration is frozen after creation.');
        }
        $roster = [];
        $withdraw = [];
        foreach ($priorRoster as $row) {
            $owner = $row['unit'] ?? 'site';
            if (in_array($owner, $plan->retires, true)) {
                $withdraw[] = $row['fqcn'];
            } elseif ($owner !== $plan->unitId) {
                $roster[$row['fqcn']] = $row;
            } elseif (!in_array($row['fqcn'], array_column($declared, 'fqcn'), true)) {
                $withdraw[] = $row['fqcn'];
            }
        }
        $providers = array_values(array_filter($composer['providers'], static fn(string $fqcn): bool => !in_array($fqcn, $withdraw, true)));
        foreach ($declared as $row) {
            if ($plan->unitId !== 'site') {
                $row['unit'] = $plan->unitId;
            }
            $roster[$row['fqcn']] = $row;
            if (($existing === null || $plan->disposition === GenerationUnitDisposition::Managed) && !in_array($row['fqcn'], $providers, true)) {
                $providers[] = $row['fqcn'];
            }
        }
        ksort($roster, SORT_STRING);
        $merge = null;
        if ($providers !== $composer['providers']) {
            if ($composer['exists'] !== true) {
                $this->unitRefusal(GenerationErrorCode::InvalidComposerProviderState, 'Registration changes require an existing application composer.json.', 'composer.json');
            }
            $merge = ['content' => $this->renderComposerProviders($composer, $providers), 'mode' => $composer['mode'], 'before_sha256' => $composer['sha256']];
        }

        return ['registrations' => array_values($roster), 'composerMerge' => $merge];
    }

    /** @param array<string, mixed> $composer @param list<string> $providers */
    private function renderComposerProviders(array $composer, array $providers): string
    {
        $raw = $composer['raw'];
        $spans = $composer['spans'];
        $tokens = [];
        if ($spans['providers'] !== null) {
            foreach ($spans['providers']['items'] as $index => $item) {
                $tokens[$composer['providers'][$index]] = substr($raw, $item['start'], (int) ($item['end'] - $item['start']));
            }
        }
        $encoded = array_map(static fn(string $provider): string => $tokens[$provider] ?? json_encode($provider, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $providers);
        $rootBytes = substr($raw, $spans['root']['start'], (int) ($spans['root']['end'] - $spans['root']['start']));
        $newline = str_contains($rootBytes, "\r\n") ? "\r\n" : "\n";
        $pretty = str_contains($rootBytes, "\n");
        preg_match('/\r?\n([ \t]+)"/', $rootBytes, $indentMatch);
        $indent = $indentMatch[1] ?? '    ';
        $colon = str_contains($rootBytes, '": ') ? ': ' : ':';
        if ($spans['providers'] !== null) {
            $span = $spans['providers'];
            $old = substr($raw, $span['start'], (int) ($span['end'] - $span['start']));
            if (str_contains($old, "\n")) {
                $base = $this->composerLineIndent($raw, (int) $span['end'] - 1);
                $itemIndent = isset($span['items'][0]) ? $this->composerLineIndent($raw, $span['items'][0]['start']) : $base . $indent;
                $replacement = $encoded === [] ? '[]' : '[' . $newline . $itemIndent . implode(',' . $newline . $itemIndent, $encoded) . $newline . $base . ']';
            } else {
                preg_match('/^\[([ \t]*)/', $old, $left);
                preg_match('/([ \t]*)\]$/', $old, $right);
                $separator = ',';
                if (count($span['items']) > 1) {
                    $gap = substr($raw, $span['items'][0]['end'], (int) ($span['items'][1]['start'] - $span['items'][0]['end']));
                    $separator = $gap;
                } elseif (($left[1] ?? '') !== '') {
                    $separator = ', ';
                }
                $replacement = '[' . ($left[1] ?? '') . implode($separator, $encoded) . ($right[1] ?? '') . ']';
            }

            return substr($raw, 0, $span['start']) . $replacement . substr($raw, $span['end']);
        }
        if ($spans['waaseyaa'] !== null) {
            $parent = $spans['waaseyaa'];
            $keys = ['providers'];
        } elseif ($spans['extra'] !== null) {
            $parent = $spans['extra'];
            $keys = ['waaseyaa', 'providers'];
        } else {
            $parent = $spans['root'];
            $keys = ['extra', 'waaseyaa', 'providers'];
        }
        if ($parent['members'] !== []) {
            $pretty = str_contains(substr($raw, $parent['start'], (int) ($parent['end'] - $parent['start'])), "\n");
        }
        $base = $this->composerLineIndent($raw, $parent['start']);
        $memberIndent = $base . $indent;
        if ($pretty && isset($parent['members'][0])) {
            $memberIndent = $this->composerLineIndent($raw, $parent['members'][0]['key_start']);
        }
        $member = $this->composerNewMember($keys, $encoded, $pretty, $memberIndent, $indent, $newline, $colon);
        if ($parent['members'] === []) {
            $replacement = $pretty ? '{' . $newline . $memberIndent . $member . $newline . $base . '}' : '{' . $member . '}';

            return substr($raw, 0, $parent['start']) . $replacement . substr($raw, $parent['end']);
        }
        $last = $parent['members'][array_key_last($parent['members'])]['value']['end'];
        $separator = $pretty ? $newline . $memberIndent : ($colon === ': ' ? ' ' : '');

        return substr($raw, 0, $last) . ',' . $separator . $member . substr($raw, $last);
    }

    private function composerLineIndent(string $raw, int $offset): string
    {
        $lineStart = strrpos(substr($raw, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($raw, $lineStart), $match);

        return $match[0];
    }

    /** @param list<string> $keys @param list<string> $encoded */
    private function composerNewMember(array $keys, array $encoded, bool $pretty, string $base, string $indent, string $newline, string $colon): string
    {
        $key = array_shift($keys);
        if ($keys === []) {
            $value = $pretty
                ? '[' . $newline . $base . $indent . implode(',' . $newline . $base . $indent, $encoded) . $newline . $base . ']'
                : '[' . implode(',', $encoded) . ']';
        } else {
            $nested = $this->composerNewMember($keys, $encoded, $pretty, $base . $indent, $indent, $newline, $colon);
            $value = $pretty ? '{' . $newline . $base . $indent . $nested . $newline . $base . '}' : '{' . $nested . '}';
        }

        return json_encode($key, JSON_THROW_ON_ERROR) . $colon . $value;
    }

    private function unitRefusal(GenerationErrorCode $code, string $message, ?string $path = null): never
    {
        throw new GenerationRefusalException('generation', [new GenerationViolation($code, $message, $path)]);
    }

    /**
     * The change receipt for one terminated apply (ADR-025 D-14.7).
     *
     * Returns null for the two outcomes that terminate before controlled apply:
     * a preview yields its evaluation and nothing more, and an operator who
     * declines at confirmation does so before a byte is staged. Neither is a
     * `no_op`, which means apply ran and found the end state already satisfied.
     *
     * v1 emits the receipt and retains none. This method returns a value; it
     * opens no file, appends to no log, and writes no record anywhere.
     */
    public function receiptFor(
        ArtifactApplyResult $result,
        string $operation,
        ?string $correlationId = null,
        ?string $causationReceiptId = null,
        ?string $decisionReceiptId = null,
        ?\DateTimeImmutable $issuedAt = null,
    ): ?ChangeReceipt {
        $outcome = ChangeOutcome::forApplyOutcome($result->outcome);
        if ($outcome === null) {
            return null;
        }
        $payload = $result->toArray();
        unset($payload['schema'], $payload['version'], $payload['outcome'], $payload['plan_digest']);

        return new ChangeReceipt(
            $this->mintIdentifier('rcpt'),
            ChangeReceipt::GENERATION_AUTHORITY,
            self::CONTRACT_VERSION,
            $operation,
            $result->planDigest,
            $outcome,
            $correlationId ?? $this->mintIdentifier('corr'),
            $issuedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ['version' => 1] + $payload,
            $causationReceiptId,
            $decisionReceiptId,
        );
    }

    private function mintIdentifier(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    /**
     * The captured precondition identity (ADR-025 D-6.2): the union of the
     * plan's artifact paths and every path recorded to a unit it supplies,
     * which is precisely the set evaluation reads.
     *
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $priorRows
     */
    private function captureProjectState(array $artifacts, array $priorRows, ?string $composerDigest = null): ProjectStateIdentity
    {
        $paths = array_values(array_unique([...array_keys($artifacts), ...array_keys($priorRows)]));
        sort($paths, SORT_STRING);

        $targets = [];
        foreach ($paths as $path) {
            $targets[] = $this->observeTarget($path);
        }

        return new ProjectStateIdentity(
            $this->observeDocument(self::METADATA),
            $this->observeDocument('.waaseyaa/site.yaml'),
            $composerDigest ?? $this->observeDocument('composer.json'),
            $targets,
        );
    }

    private function observeTarget(string $path): ProjectStateTarget
    {
        $absolute = $this->absolute($path);
        if (is_link($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Other, ProjectStateIdentity::ABSENT_DIGEST, ObservedTargetMode::Other);
        }
        if (!file_exists($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Absent);
        }
        if (!is_file($absolute)) {
            return new ProjectStateTarget($path, ObservedTargetState::Other, ProjectStateIdentity::ABSENT_DIGEST, ObservedTargetMode::Other);
        }

        return new ProjectStateTarget($path, ObservedTargetState::File, $this->digestFile($absolute), $this->observeMode($absolute));
    }

    private function observeMode(string $absolute): ObservedTargetMode
    {
        if (!$this->platform->enforcesPermissionBits()) {
            return ObservedTargetMode::Unknown;
        }
        $bits = fileperms($absolute);

        return $bits === false
            ? ObservedTargetMode::Other
            : ObservedTargetMode::tryFrom(sprintf('%04o', $bits & 0o777)) ?? ObservedTargetMode::Other;
    }

    private function observeDocument(string $path): string
    {
        $absolute = $this->absolute($path);

        return is_file($absolute) ? $this->digestFile($absolute) : ProjectStateIdentity::ABSENT_DIGEST;
    }

    /**
     * Target evaluation, extracted from `prepare()` so that dry-run, apply and
     * plan evaluation enter one implementation rather than three (ADR-025 D-6.2,
     * and D-13's prohibition on a second collision, containment or
     * symlink-safety check).
     *
     * It stays physically below the prior-state admission block on purpose. The
     * managed-byte freeze must run BEFORE this loop: a row whose recorded digest
     * was corrupted satisfies both refusals at once, so only statement order
     * decides which message an operator sees, and that message is frozen.
     *
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $priorRows
     * @return array{prepared: array<string, GeneratedArtifact>, status: array<string, ArtifactStatus>}
     */
    private function evaluateTargets(array $artifacts, bool $hasMetadata, array $priorRows): array
    {
        $prepared = [];
        $status = [];
        foreach ($artifacts as $path => $artifact) {
            $this->assertSafeTarget($path);
            $absolute = $this->absolute($path);
            $existed = file_exists($absolute) || is_link($absolute);
            if ($existed) {
                $bootstrapControlIgnore = !$hasMetadata
                    && $path === '.waaseyaa/.gitignore'
                    && is_file($absolute)
                    && hash_equals(hash('sha256', $artifact->content), $this->digestFile($absolute));
                if (!$hasMetadata && !$bootstrapControlIgnore || $path === self::METADATA && !is_file($absolute)) {
                    throw new SiteInitializationCollisionException("Refusing to overwrite unowned artifact: {$path}");
                }
                $this->assertRegularOwnedFile($absolute, $path);
                $existing = (string) file_get_contents($absolute);
                if ($path !== self::METADATA && !$bootstrapControlIgnore) {
                    $row = $priorRows[$path] ?? null;
                    try {
                        $managedDigest = $artifact->managedDigest($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                    if (!is_array($row) || !hash_equals($row['managed_sha256'], $managedDigest)) {
                        throw new SiteInitializationCollisionException("Generated artifact was edited outside an extension region: {$path}");
                    }
                    if (($row['extension_region'] ?? null) !== $artifact->extensionRegion) {
                        throw new SiteInitializationCollisionException("Generated extension ownership changed unexpectedly: {$path}");
                    }
                    try {
                        $artifact = $artifact->withExtensionFrom($existing);
                    } catch (\InvalidArgumentException $exception) {
                        throw new SiteInitializationCollisionException("Generated artifact has a damaged extension region: {$path}", previous: $exception);
                    }
                }
                if (hash_equals(hash('sha256', $existing), hash('sha256', $artifact->content)) && $this->modeMatches($absolute, $artifact->mode)) {
                    $status[$path] = ArtifactStatus::Unchanged;
                    continue;
                }
            }
            $status[$path] = $existed ? ArtifactStatus::Changed : ArtifactStatus::Created;
            $prepared[$path] = $artifact;
        }
        ksort($prepared, SORT_STRING);
        ksort($status, SORT_STRING);

        return ['prepared' => $prepared, 'status' => $status];
    }

    /**
     * @param array<string, GeneratedArtifact> $artifacts
     * @param array<string, array<string, mixed>> $retirements
     * @param array{content: string, mode: int, before_sha256: string}|null $composerMerge
     */
    private function publish(array $artifacts, array $retirements = [], ?array $composerMerge = null): bool
    {
        $transactionId = bin2hex(random_bytes(12));
        $stageRelative = '.waaseyaa/site-init-stage-' . $transactionId;
        $backupRelative = '.waaseyaa/site-init-backup-' . $transactionId;
        $stage = $this->absolute($stageRelative);
        $backup = $this->absolute($backupRelative);
        $this->makePrivateDirectory($stage);
        $this->makePrivateDirectory($backup);

        $publishOrder = array_keys($artifacts + $retirements);
        if ($composerMerge !== null) {
            $publishOrder[] = 'composer.json';
        }
        if ($retirements !== [] || $composerMerge !== null) {
            sort($publishOrder, SORT_STRING);
        }
        $publishOrder = array_values(array_filter($publishOrder, static fn(string $path): bool => $path !== self::METADATA));
        if (isset($artifacts[self::METADATA])) {
            $publishOrder[] = self::METADATA;
        }
        $items = [];
        foreach ($publishOrder as $index => $path) {
            $removing = isset($retirements[$path]);
            $merging = $path === 'composer.json' && $composerMerge !== null;
            $artifact = $artifacts[$path] ?? null;
            $mode = $merging ? $composerMerge['mode'] : ($removing ? intval($retirements[$path]['mode'], 8) : $artifact->mode);
            $stageFile = $stage . '/' . sprintf('%04d.artifact', $index);
            if (!$removing) {
                $this->writeDurably($stageFile, $merging ? $composerMerge['content'] : $artifact->content, $mode);
                $this->injectFault('after-stage', $index, $path);
            }
            $target = $this->absolute($path);
            if ($removing || $merging) {
                $this->assertSafeTarget($path);
                $this->assertRegularOwnedFile($target, $path);
            }
            $existed = is_file($target);
            $backupFile = null;
            $backupMode = null;
            if ($existed) {
                $backupFile = $backup . '/' . sprintf('%04d.backup', $index);
                // A host without permission bits has no observed mode to preserve, so the
                // journal records the declared one and rollback stays reproducible.
                $backupMode = $this->platform->enforcesPermissionBits() ? fileperms($target) & 0o777 : $mode;
                $this->copyDurably($target, $backupFile, $backupMode);
                $this->injectFault('after-backup', $index, $path);
            }
            $items[] = [
                'path' => $path,
                'stage' => $removing ? null : $this->relative($stageFile),
                'installed_sha256' => $removing ? null : $this->digestFile($stageFile),
                'backup' => $backupFile === null ? null : $this->relative($backupFile),
                'backup_sha256' => $backupFile === null ? null : $this->digestFile($backupFile),
                'backup_mode' => $backupMode,
                'existed' => $existed,
                'mode' => $mode,
                'state' => 'pending',
            ];
            if ($removing || $merging) {
                $items[array_key_last($items)]['kind'] = $merging ? 'composer-merge' : 'remove';
            }
        }
        $journal = [
            'schema' => 'waaseyaa.site-init-transaction',
            'version' => 1,
            'id' => $transactionId,
            'state' => 'prepared',
            'stage' => $stageRelative,
            'backup' => $backupRelative,
            'created_directories' => $this->missingTargetDirectories(array_keys($artifacts)),
            'items' => $items,
        ];
        if ($retirements !== []) {
            $journal['removed_directories'] = $this->retirementDirectories(array_keys($retirements));
        }
        $this->writeJournal($journal);

        try {
            foreach ($journal['items'] as $index => &$item) {
                $item['state'] = 'installing';
                $this->writeJournal($journal);
                if (($item['kind'] ?? null) === 'remove') {
                    $this->injectFault('before-remove', $index, $item['path']);
                    $target = $this->absolute($item['path']);
                    $this->assertSafeTarget($item['path']);
                    $this->assertRegularOwnedFile($target, $item['path']);
                    if (!hash_equals($item['backup_sha256'], $this->digestFile($target))) {
                        throw new SiteInitializationCollisionException("Cannot retire a changed generated target: {$item['path']}");
                    }
                    if (!unlink($target)) {
                        throw new \RuntimeException("Unable to retire {$item['path']}.");
                    }
                    $this->syncDirectory(dirname($target));
                    $this->injectFault('after-remove', $index, $item['path']);
                    $item['state'] = 'applied';
                    $this->writeJournal($journal);
                    continue;
                }
                $this->injectFault('before-replace', $index, $item['path']);
                $target = $this->absolute($item['path']);
                $this->ensureTargetDirectory(dirname($target));
                if (!rename($this->absolute($item['stage']), $target)) {
                    throw new \RuntimeException("Unable to atomically install {$item['path']}.");
                }
                if ($this->platform->enforcesPermissionBits() && !chmod($target, $item['mode'])) {
                    throw new \RuntimeException("Unable to set mode on {$item['path']}.");
                }
                $this->syncFile($target);
                $this->syncDirectory(dirname($target));
                $this->injectFault('after-replace', $index, $item['path']);
                $item['state'] = 'applied';
                $this->writeJournal($journal);
            }
            unset($item);
            if (isset($journal['removed_directories'])) {
                foreach ($journal['removed_directories'] as $index => &$directory) {
                    $absolute = $this->absolute($directory['path']);
                    $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                    if (!is_dir($absolute) || is_link($absolute) || !$this->directoryIsEmpty($absolute)) {
                        continue;
                    }
                    $directory['state'] = 'removing';
                    $this->writeJournal($journal);
                    $this->injectFault('before-remove-directory', (int) $index, $directory['path']);
                    if (!rmdir($absolute)) {
                        throw new \RuntimeException("Unable to remove retired directory {$directory['path']}.");
                    }
                    $this->syncDirectory(dirname($absolute));
                    $this->injectFault('after-remove-directory', (int) $index, $directory['path']);
                    $directory['state'] = 'applied';
                    $this->writeJournal($journal);
                }
                unset($directory);
            }
            $journal['state'] = 'committed';
            $this->writeJournal($journal);
        } catch (\Exception $exception) {
            unset($item);
            $this->rollback($journal, $retirements !== [] || $composerMerge !== null);
            throw $exception;
        }
        try {
            $this->injectFault('after-commit', -1, '');
            $this->cleanupTransaction($journal);
        } catch (\Exception) {
            return true;
        }

        return false;
    }

    private function recoverIfRequired(bool $unitAware = false): bool
    {
        $path = $this->absolute(self::JOURNAL);
        if (!is_file($path)) {
            return $this->cleanupOrphanControlResidue();
        }
        $this->assertRegularOwnedFile($path, self::JOURNAL);
        try {
            $journal = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid JSON.', previous: $exception);
        }
        if (!is_array($journal)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $this->validateJournal($journal, $unitAware);
        if ($journal['state'] === 'committed') {
            $this->cleanupTransaction($journal);
        } elseif ($unitAware && $this->hasMissingRollbackBackup($journal)) {
            // Cleanup can be interrupted after backups disappear but before
            // the prepared journal is unlinked. Only a complete proof of the
            // prior state permits finishing that cleanup without those backups.
            $this->assertFullyRestoredTransaction($journal);
            $this->cleanupTransaction($journal);
        } else {
            $this->rollback($journal, $unitAware);
        }

        $this->cleanupOrphanControlResidue();

        return true;
    }

    /** @param array<string, mixed> $journal */
    private function rollback(array $journal, bool $unitAware = false): void
    {
        if ($unitAware) {
            // Prove every removal before restoring any item or deleting its
            // recovery evidence. Already-restored exact tuples are valid after
            // an interruption; merely matching bytes are not enough.
            $this->validateRetirementRecoveryState($journal);
            $this->validateComposerMergeRecoveryState($journal);
            foreach (array_reverse($journal['removed_directories'] ?? []) as $directory) {
                if ($directory['state'] === 'pending') {
                    continue;
                }
                $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                $absolute = $this->absolute($directory['path']);
                if (is_link($absolute) || (file_exists($absolute) && !is_dir($absolute))) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed target directory: {$directory['path']}");
                }
                if (!is_dir($absolute)) {
                    if (!mkdir($absolute, $directory['mode'])) {
                        throw new \RuntimeException("Cannot restore target directory {$directory['path']}.");
                    }
                    if ($this->platform->enforcesPermissionBits() && !chmod($absolute, $directory['mode'])) {
                        throw new \RuntimeException("Cannot restore target directory mode {$directory['path']}.");
                    }
                    $this->syncDirectory(dirname($absolute));
                    $this->injectFault('after-rollback-directory', -1, $directory['path']);
                } elseif ($this->platform->enforcesPermissionBits() && (fileperms($absolute) & 0o777) !== $directory['mode']) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed target directory mode: {$directory['path']}");
                }
            }
        }
        foreach (array_reverse($journal['items'], true) as $index => $item) {
            if (!in_array($item['state'], ['installing', 'applied'], true)) {
                continue;
            }
            $target = $this->absolute($item['path']);
            if ($item['existed'] === true) {
                $backup = $this->absolute($item['backup']);
                if (!is_file($backup) || is_link($backup)) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup is missing.");
                }
                $this->assertRegularOwnedFile($backup, $item['backup']);
                if (!hash_equals($item['backup_sha256'], $this->digestFile($backup))) {
                    throw new \RuntimeException("Cannot recover {$item['path']}: its backup was substituted.");
                }
                if ($unitAware && ($item['kind'] ?? null) === 'remove' && !file_exists($target) && !is_link($target)) {
                    $this->assertSafeTarget($item['path']);
                    $this->assertPathWithinRoot(dirname($target));
                    $temp = dirname($backup) . '/restore-' . sprintf('%04d', $index) . '-' . bin2hex(random_bytes(6));
                    $this->copyDurably($backup, $temp, $item['backup_mode']);
                    $this->injectFault('after-rollback-copy', (int) $index, $item['path']);
                    if (!rename($temp, $target)) {
                        @unlink($temp);
                        throw new \RuntimeException("Cannot restore {$item['path']}.");
                    }
                    $this->syncDirectory(dirname($target));
                    $this->injectFault('after-rollback-restore', (int) $index, $item['path']);
                    continue;
                }
                if (!is_file($target) || is_link($target)) {
                    throw new SiteInitializationCollisionException("Cannot recover a changed generated target: {$item['path']}");
                }
                $this->assertRegularOwnedFile($target, $item['path']);
                $currentDigest = $this->digestFile($target);
                if (hash_equals($item['backup_sha256'], $currentDigest)) {
                    if (!$this->modeMatches($target, $item['backup_mode']) && !chmod($target, $item['backup_mode'])) {
                        throw new \RuntimeException("Cannot restore the mode of {$item['path']}.");
                    }
                    $this->syncFile($target);
                    continue;
                }
                if (($unitAware && ($item['kind'] ?? null) === 'remove') || !hash_equals($item['installed_sha256'], $currentDigest)) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                $temp = dirname($backup) . '/restore-' . sprintf('%04d', $index) . '-' . bin2hex(random_bytes(6));
                $this->copyDurably($backup, $temp, $item['backup_mode']);
                $this->injectFault('after-rollback-copy', (int) $index, $item['path']);
                if (!rename($temp, $target)) {
                    @unlink($temp);
                    throw new \RuntimeException("Cannot restore {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            } elseif (file_exists($target) || is_link($target)) {
                $this->assertRegularOwnedFile($target, $item['path']);
                if (!hash_equals($item['installed_sha256'], $this->digestFile($target))) {
                    throw new SiteInitializationCollisionException("Cannot recover a substituted generated target: {$item['path']}");
                }
                if (!unlink($target)) {
                    throw new \RuntimeException("Cannot remove interrupted artifact {$item['path']}.");
                }
                $this->syncDirectory(dirname($target));
            }
        }
        foreach (array_reverse($journal['created_directories']) as $relative) {
            $directory = $this->absolute($relative);
            if (is_dir($directory) && $this->directoryIsEmpty($directory)) {
                if (!rmdir($directory)) {
                    throw new \RuntimeException("Cannot remove interrupted target directory {$relative}.");
                }
                $this->syncDirectory(dirname($directory));
            }
        }
        if ($unitAware) {
            $this->injectFault('before-rollback-cleanup', -1, '');
        }
        $this->cleanupTransaction($journal);
    }

    /** @param array<string, mixed> $journal */
    private function hasMissingRollbackBackup(array $journal): bool
    {
        foreach ($journal['items'] as $item) {
            if ($item['existed'] === true && !is_file($this->absolute($item['backup']))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $journal */
    private function assertFullyRestoredTransaction(array $journal): void
    {
        foreach ($journal['items'] as $item) {
            $this->assertSafeTarget($item['path'], true);
            $absolute = $this->absolute($item['path']);
            $present = file_exists($absolute) || is_link($absolute);
            if ($item['existed'] === false) {
                if ($present) {
                    throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a newly present target: {$item['path']}");
                }
                continue;
            }
            if (!$present) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a missing prior target: {$item['path']}");
            }
            $this->assertRegularOwnedFile($absolute, $item['path']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($absolute)) || !$this->modeMatches($absolute, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a changed prior target: {$item['path']}");
            }
        }
        foreach ($journal['created_directories'] as $relative) {
            $this->assertSafeTarget($relative . '/placeholder', true);
            if (file_exists($this->absolute($relative)) || is_link($this->absolute($relative))) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with a newly present directory: {$relative}");
            }
        }
        foreach ($journal['removed_directories'] ?? [] as $directory) {
            $this->assertSafeTarget($directory['path'] . '/placeholder', true);
            $absolute = $this->absolute($directory['path']);
            if (!is_dir($absolute) || is_link($absolute) || !$this->modeMatches($absolute, $directory['mode'])) {
                throw new SiteInitializationCollisionException("Cannot finish recovery cleanup with an unrestored prior directory: {$directory['path']}");
            }
        }
        $this->validateRetirementRecoveryState($journal);
    }

    /** @param array<string, mixed> $journal */
    private function validateComposerMergeRecoveryState(array $journal): void
    {
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) !== 'composer-merge') {
                continue;
            }
            // A pending merge has not touched the manifest. Later states may
            // hold either the installed tuple or an exact prior restoration.
            // Prove all tuples before any rollback item mutates the project.
            $this->assertSafeTarget($item['backup'], true);
            $backup = $this->absolute($item['backup']);
            $this->assertRegularOwnedFile($backup, $item['backup']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($backup)) || !$this->modeMatches($backup, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException('Cannot recover composer.json: its backup was substituted.');
            }
            $this->assertSafeTarget($item['path'], true);
            $target = $this->absolute($item['path']);
            $this->assertRegularOwnedFile($target, $item['path']);
            $digest = $this->digestFile($target);
            $prior = hash_equals($item['backup_sha256'], $digest) && $this->modeMatches($target, $item['backup_mode']);
            $installed = $item['state'] !== 'pending'
                && hash_equals($item['installed_sha256'], $digest)
                && $this->modeMatches($target, $item['mode']);
            if (!$prior && !$installed) {
                throw new SiteInitializationCollisionException('Cannot recover a changed Composer manifest.');
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function validateRetirementRecoveryState(array $journal): void
    {
        $removals = [];
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) !== 'remove') {
                continue;
            }
            $removals[$item['path']] = $item;
            $this->assertUnitOwnershipPath($item['path']);
            $target = $this->absolute($item['path']);
            $present = file_exists($target) || is_link($target);
            if (!$present) {
                if ($item['state'] === 'pending') {
                    throw new SiteInitializationCollisionException("Cannot recover a missing pending retirement target: {$item['path']}");
                }
                continue;
            }
            $this->assertRegularOwnedFile($target, $item['path']);
            if (!hash_equals($item['backup_sha256'], $this->digestFile($target)) || !$this->modeMatches($target, $item['backup_mode'])) {
                throw new SiteInitializationCollisionException("Cannot recover a changed retirement target: {$item['path']}");
            }
        }
        $directories = [];
        foreach ($journal['removed_directories'] ?? [] as $directory) {
            $directories[$directory['path']] = $directory;
        }
        foreach ($directories as $directory) {
            if ($directory['state'] === 'pending') {
                continue;
            }
            $this->assertSafeTarget($directory['path'] . '/placeholder', true);
            $absolute = $this->absolute($directory['path']);
            if (!file_exists($absolute) && !is_link($absolute)) {
                continue;
            }
            if (!is_dir($absolute) || is_link($absolute) || !$this->modeMatches($absolute, $directory['mode'])) {
                throw new SiteInitializationCollisionException("Cannot recover a changed retirement directory: {$directory['path']}");
            }
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                $relative = $this->relative($entry->getPathname());
                $this->assertSafeTarget($relative, true);
                if ($entry->isLink()) {
                    throw new SiteInitializationCollisionException("Cannot recover a linked retirement directory entry: {$relative}");
                }
                if ($entry->isDir()) {
                    if (!isset($directories[$relative]) || !$this->modeMatches($entry->getPathname(), $directories[$relative]['mode'])) {
                        throw new SiteInitializationCollisionException("Cannot recover an unknown retirement directory entry: {$relative}");
                    }
                } elseif (!isset($removals[$relative])) {
                    throw new SiteInitializationCollisionException("Cannot recover an unknown retirement directory entry: {$relative}");
                }
                // Every recognized file was proven against its original
                // digest, private-file identity and mode in the first loop.
            }
        }
    }

    /** @param array<string, mixed> $journal */
    private function cleanupTransaction(array $journal): void
    {
        $this->removeControlTree($this->absolute($journal['stage']));
        $this->removeControlTree($this->absolute($journal['backup']));
        $journalPath = $this->absolute(self::JOURNAL);
        if (is_file($journalPath)) {
            if (!unlink($journalPath)) {
                throw new \RuntimeException('Unable to remove the completed site initialization journal.');
            }
            $this->syncDirectory(dirname($journalPath));
        }
    }

    /** @return array<string, mixed> */
    private function readMetadata(string $path, bool $unitAware = false): array
    {
        $raw = (string) file_get_contents($path);
        try {
            $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SiteInitializationCollisionException('Generated ownership metadata is invalid.', previous: $exception);
        }
        if (!is_array($metadata)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        if ($unitAware && (!is_string($metadata['manifest_digest'] ?? null))) {
            $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The root digest must be a string.');
        }
        $metadataKeys = array_keys($metadata);
        sort($metadataKeys, SORT_STRING);
        $allowedMetadataKeys = ['artifacts', 'generator_version', 'manifest_digest', 'schema', 'version'];
        if ($unitAware) {
            foreach (['units', 'registrations'] as $member) {
                if (array_key_exists($member, $metadata)) {
                    $allowedMetadataKeys[] = $member;
                }
            }
            sort($allowedMetadataKeys, SORT_STRING);
            if (array_key_exists('registrations', $metadata)) {
                $this->validateRegistrationRosterShape($metadata['registrations']);
            }
        }
        if ($metadataKeys !== $allowedMetadataKeys
            || ($metadata['schema'] ?? null) !== 'waaseyaa.generated'
            || ($metadata['version'] ?? null) !== 1
            || !is_int($metadata['generator_version'] ?? null)
            || $metadata['generator_version'] < 1
            || preg_match('/^[a-f0-9]{64}$/D', $metadata['manifest_digest'] ?? '') !== 1
            || !is_array($metadata['artifacts'] ?? null)
            || !hash_equals(CanonicalJson::encode($metadata) . "\n", $raw)) {
            throw new SiteInitializationCollisionException('Generated ownership metadata has an unsupported shape.');
        }
        $unitIds = [];
        if ($unitAware) {
            if (!array_is_list($metadata['artifacts'])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Artifact records must be a list.');
            }
            $unitRows = $metadata['units'] ?? [];
            if (!is_array($unitRows) || !array_is_list($unitRows) || (array_key_exists('units', $metadata) && $unitRows === [])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The unit roster must be a nonempty list when present.');
            }
            $previousId = null;
            foreach ($unitRows as $unit) {
                if (!is_array($unit)) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A unit record must be an object.');
                }
                $keys = array_keys($unit);
                sort($keys, SORT_STRING);
                $generator = $unit['generator'] ?? null;
                $generatorKeys = is_array($generator) ? array_keys($generator) : [];
                sort($generatorKeys, SORT_STRING);
                if ($keys !== ['disposition', 'generator', 'id', 'input_digest']
                    || !is_string($unit['id']) || !is_string($unit['input_digest'])
                    || !in_array($unit['disposition'], ['managed', 'seeded'], true)
                    || $generatorKeys !== ['fqcn', 'version']
                    || !is_string($generator['fqcn']) || $generator['fqcn'] === ''
                    || !is_int($generator['version']) || $generator['version'] < 1
                    || preg_match('/^[a-f0-9]{64}$/D', $unit['input_digest']) !== 1) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'The unit record has an unsupported shape.');
                }
                $id = $unit['id'];
                if (strlen($id) > 128 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)*$/D', $id) !== 1) {
                    $this->unitRefusal(GenerationErrorCode::MaliciousIdentifier, 'The unit id is invalid.');
                }
                if ($id === 'site' || ($previousId !== null && strcmp($previousId, $id) >= 0)) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'Unit ids must be unique, non-root and sorted.');
                }
                $previousId = $id;
                $unitIds[$id] = true;
            }
        }
        if ($unitAware && array_key_exists('registrations', $metadata)) {
            $this->validateRegistrationRosterOwnership($metadata['registrations'], $unitIds);
        }
        $paths = [];
        foreach ($metadata['artifacts'] as $row) {
            if ($unitAware && (!is_array($row) || !is_string($row['path'] ?? null) || !is_string($row['managed_sha256'] ?? null) || !is_string($row['mode'] ?? null)
                || (array_key_exists('extension_region', $row) && !is_string($row['extension_region'])))) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'An artifact record has invalid member types.');
            }
            if ($unitAware && is_array($row) && is_string($row['path'] ?? null) && isset($paths[$row['path']])) {
                $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'A path is owned more than once.', $row['path']);
            }
            if (!is_array($row) || !is_string($row['path'] ?? null) || isset($paths[$row['path']]) || preg_match('/^[a-f0-9]{64}$/D', $row['managed_sha256'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an invalid artifact record.');
            }
            $allowed = isset($row['extension_region'])
                ? ['extension_region', 'managed_sha256', 'mode', 'path']
                : ['managed_sha256', 'mode', 'path'];
            if ($unitAware && array_key_exists('unit', $row)) {
                if (!is_string($row['unit']) || !isset($unitIds[$row['unit']])) {
                    $this->unitRefusal(GenerationErrorCode::UnitPathConflict, 'An artifact names an unknown unit.', $row['path']);
                }
                $allowed[] = 'unit';
                sort($allowed, SORT_STRING);
            }
            $keys = array_keys($row);
            sort($keys, SORT_STRING);
            if ($keys !== $allowed || preg_match('/^0(?:644|755)$/D', $row['mode'] ?? '') !== 1) {
                throw new SiteInitializationCollisionException('Generated ownership metadata contains an unsupported artifact record.');
            }
            if ($unitAware) {
                $this->assertUnitOwnershipPath($row['path']);
                $absolute = $this->absolute($row['path']);
                if (file_exists($absolute) || is_link($absolute)) {
                    $this->assertRegularOwnedFile($absolute, $row['path']);
                }
            }
            $paths[$row['path']] = true;
        }
        $sortedPaths = array_keys($paths);
        $recordedPaths = $sortedPaths;
        sort($sortedPaths, SORT_STRING);
        if ($recordedPaths !== $sortedPaths) {
            throw new SiteInitializationCollisionException('Generated ownership metadata artifact records are not canonical.');
        }

        return $metadata;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $this->writeAtomically($this->absolute(self::JOURNAL), json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 0o600);
    }

    private function writeAtomically(string $target, string $content, int $mode): void
    {
        $temp = $target . '.tmp-' . bin2hex(random_bytes(6));
        $this->writeDurably($temp, $content, $mode);
        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new \RuntimeException("Unable to publish control file {$target}.");
        }
        $this->syncDirectory(dirname($target));
    }

    private function writeDurably(string $path, string $content, int $mode): void
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to create {$path} exclusively.");
        }
        try {
            $written = fwrite($handle, $content);
            if ($written !== strlen($content) || !fflush($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
            if ($this->platform->enforcesPermissionBits() && !chmod($path, $mode)) {
                throw new \RuntimeException("Unable to set mode on {$path}.");
            }
            if (!fsync($handle)) {
                throw new \RuntimeException("Unable to durably write {$path}.");
            }
        } finally {
            fclose($handle);
        }
        $this->syncDirectory(dirname($path));
    }

    private function copyDurably(string $source, string $target, int $mode): void
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new \RuntimeException("Unable to read {$source} for recovery.");
        }
        $this->writeDurably($target, $content, $mode);
    }

    private function digestFile(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new \RuntimeException("Unable to digest {$path}.");
        }

        return $digest;
    }

    private function syncFile(string $path): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync {$path}.");
        }
        fclose($handle);
    }

    private function syncDirectory(string $directory): void
    {
        if (!$this->platform->synchronizesDirectories()) {
            return;
        }
        $handle = fopen($directory, 'rb');
        if ($handle === false || !fsync($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException("Unable to sync directory {$directory}.");
        }
        fclose($handle);
    }

    private function makePrivateDirectory(string $directory): void
    {
        if (!mkdir($directory, 0o700) || is_link($directory)) {
            throw new \RuntimeException("Unable to create transaction directory {$directory}.");
        }
        $this->syncDirectory(dirname($directory));
    }

    private function ensureTargetDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create target directory {$directory}.");
        }
        $this->assertPathWithinRoot($directory);
    }

    /** @param list<string> $paths @return list<string> */
    private function missingTargetDirectories(array $paths): array
    {
        $directories = [];
        foreach ($paths as $path) {
            $relative = dirname($path);
            while ($relative !== '.' && $relative !== '.waaseyaa') {
                if (!is_dir($this->absolute($relative))) {
                    $directories[$relative] = substr_count($relative, '/');
                }
                $relative = dirname($relative);
            }
        }
        uasort($directories, static fn(int $left, int $right): int => $left <=> $right);

        return array_keys($directories);
    }

    /** @param list<string> $paths @return list<array{path: string, mode: int, state: string}> */
    private function retirementDirectories(array $paths): array
    {
        $directories = [];
        foreach ($paths as $path) {
            $relative = dirname($path);
            while ($relative !== '.' && $relative !== '.waaseyaa') {
                $this->assertSafeTarget($relative . '/placeholder');
                $absolute = $this->absolute($relative);
                if (is_dir($absolute)) {
                    $directories[$relative] = [
                        'path' => $relative,
                        'mode' => $this->platform->enforcesPermissionBits() ? fileperms($absolute) & 0o777 : 0o755,
                        'state' => 'pending',
                    ];
                }
                $relative = dirname($relative);
            }
        }
        uksort($directories, static function (string $left, string $right): int {
            $depth = substr_count($right, '/') <=> substr_count($left, '/');

            return $depth !== 0 ? $depth : strcmp($left, $right);
        });

        return array_values($directories);
    }

    private function assertUnitOwnershipPath(string $path): void
    {
        $this->assertSafeTarget($path, true);
        if ($path === 'composer.json' || $path === self::METADATA || $path === self::LOCK || $path === self::JOURNAL
            || str_starts_with($path, '.waaseyaa/site-init-')
            || str_starts_with($path, self::JOURNAL . '.')) {
            $this->unitRefusal(GenerationErrorCode::CollisionRefused, 'Transaction control state cannot be owned by a generation unit.', $path);
        }
    }

    private function assertSafeTarget(string $relative, bool $canonical = false): void
    {
        if ($canonical && (in_array('', explode('/', $relative), true) || in_array('.', explode('/', $relative), true))) {
            $this->unitRefusal(GenerationErrorCode::UnsafePath, 'Unit-owned paths must have canonical nonempty segments.', $relative);
        }
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains("/{$relative}/", '/../') || str_contains($relative, "\0")) {
            throw new SiteInitializationCollisionException("Unsafe generated target: {$relative}");
        }
        $cursor = $this->projectRoot;
        foreach (explode('/', dirname($relative)) as $segment) {
            if ($segment === '.') {
                continue;
            }
            $cursor .= '/' . $segment;
            if (is_link($cursor)) {
                throw new SiteInitializationCollisionException("Generated target traverses a symbolic link: {$relative}");
            }
        }
    }

    private function assertRegularOwnedFile(string $path, string $relative): void
    {
        $stat = lstat($path);
        // The hard-link count is a POSIX guarantee; on Windows it is not a
        // portable signal, so enforcing it there would refuse ordinary files.
        // The symlink and regular-file clauses stay unconditional (#2644).
        $aliased = $this->platform->enforcesHardLinkCounts() && ($stat === false || $stat['nlink'] !== 1);
        if ($stat === false || !is_file($path) || is_link($path) || $aliased) {
            throw new SiteInitializationCollisionException("Generated target is not a private regular file: {$relative}");
        }
    }

    /**
     * Whether an existing artifact already carries its declared mode.
     *
     * On a host without POSIX permission bits there is nothing to compare, so
     * the mode half of the unchanged-artifact test is vacuously satisfied.
     * Comparing anyway meant no artifact ever matched and `site:init` rewrote
     * the entire generated set on every run (#2644).
     */
    private function modeMatches(string $absolute, int $mode): bool
    {
        if (!$this->platform->enforcesPermissionBits()) {
            return true;
        }

        return (fileperms($absolute) & 0o777) === $mode;
    }

    private function assertPathWithinRoot(string $path): void
    {
        $resolved = realpath($path);
        // #2644: realpath() returns backslash-separated paths on Windows, so a
        // separator-naive prefix test rejected every legitimate target there.
        if ($resolved === false || !SitePathContainment::contains($this->projectRoot, $resolved)) {
            throw new SiteInitializationCollisionException('Generated target escaped the project root.');
        }
    }

    private function absolute(string $relative): string
    {
        return $this->projectRoot . '/' . $relative;
    }

    private function relative(string $absolute): string
    {
        return substr($absolute, strlen($this->projectRoot) + 1);
    }

    /** @param array<string, mixed> $journal */
    private function validateJournal(array $journal, bool $unitAware = false): void
    {
        $keys = array_keys($journal);
        sort($keys, SORT_STRING);
        $expectedKeys = ['backup', 'created_directories', 'id', 'items', 'schema', 'stage', 'state', 'version'];
        if ($unitAware && array_key_exists('removed_directories', $journal)) {
            $expectedKeys[] = 'removed_directories';
            sort($expectedKeys, SORT_STRING);
        }
        if ($keys !== $expectedKeys
            || ($journal['schema'] ?? null) !== 'waaseyaa.site-init-transaction'
            || ($journal['version'] ?? null) !== 1
            || preg_match('/^[a-f0-9]{24}$/D', $journal['id'] ?? '') !== 1
            || ($journal['stage'] ?? null) !== '.waaseyaa/site-init-stage-' . $journal['id']
            || ($journal['backup'] ?? null) !== '.waaseyaa/site-init-backup-' . $journal['id']
            || !in_array($journal['state'] ?? null, ['prepared', 'committed'], true)
            || !is_array($journal['items'] ?? null)
            || !is_array($journal['created_directories'] ?? null)) {
            throw new \RuntimeException('The interrupted site initialization journal is invalid.');
        }
        $paths = [];
        foreach ($journal['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            $itemKeys = array_keys($item);
            sort($itemKeys, SORT_STRING);
            $removing = $unitAware && ($item['kind'] ?? null) === 'remove';
            $merging = $unitAware && ($item['kind'] ?? null) === 'composer-merge';
            $expectedItemKeys = ['backup', 'backup_mode', 'backup_sha256', 'existed', 'installed_sha256', 'mode', 'path', 'stage', 'state'];
            if ($removing || $merging) {
                $expectedItemKeys[] = 'kind';
                sort($expectedItemKeys, SORT_STRING);
            }
            if ($itemKeys !== $expectedItemKeys
                || !is_string($item['path'] ?? null)
                || isset($paths[$item['path']])
                || !is_bool($item['existed'] ?? null)
                || ($merging
                    ? (!is_int($item['mode'] ?? null) || $item['mode'] < 0 || $item['mode'] > 0o777 || $item['path'] !== 'composer.json' || $item['existed'] !== true)
                    : !in_array($item['mode'] ?? null, [0o644, 0o755], true))
                || !in_array($item['state'] ?? null, ['pending', 'installing', 'applied'], true)
                || ($removing
                    ? ($item['installed_sha256'] !== null || $item['stage'] !== null || $item['existed'] !== true || !array_key_exists('removed_directories', $journal))
                    : (preg_match('/^[a-f0-9]{64}$/D', $item['installed_sha256'] ?? '') !== 1
                        || ($item['stage'] ?? null) !== $journal['stage'] . '/' . sprintf('%04d.artifact', $index)))
                || ($item['existed'] === true && (($item['backup'] ?? null) !== $journal['backup'] . '/' . sprintf('%04d.backup', $index) || preg_match('/^[a-f0-9]{64}$/D', $item['backup_sha256'] ?? '') !== 1 || !is_int($item['backup_mode'] ?? null) || $item['backup_mode'] < 0 || $item['backup_mode'] > 0o777))
                || ($item['existed'] === false && (($item['backup'] ?? null) !== null || ($item['backup_sha256'] ?? null) !== null || ($item['backup_mode'] ?? null) !== null))) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid item.');
            }
            if ($removing) {
                $this->assertUnitOwnershipPath($item['path']);
            }
            $this->assertSafeTarget($item['path']);
            $paths[$item['path']] = true;
        }
        if ($unitAware && array_key_exists('removed_directories', $journal)) {
            if (!is_array($journal['removed_directories']) || !array_is_list($journal['removed_directories'])) {
                throw new \RuntimeException('The interrupted site initialization journal contains invalid retired directories.');
            }
            $seenDirectories = [];
            foreach ($journal['removed_directories'] as $directory) {
                if (!is_array($directory)) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an invalid retired directory.');
                }
                $directoryKeys = array_keys($directory);
                sort($directoryKeys, SORT_STRING);
                if ($directoryKeys !== ['mode', 'path', 'state'] || !is_string($directory['path'])
                    || in_array($directory['path'], ['', '.', '.waaseyaa'], true) || isset($seenDirectories[$directory['path']])
                    || !is_int($directory['mode']) || $directory['mode'] < 0 || $directory['mode'] > 0o777
                    || !in_array($directory['state'], ['pending', 'removing', 'applied'], true)) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an invalid retired directory.');
                }
                $this->assertSafeTarget($directory['path'] . '/placeholder', true);
                $ownsRemoval = false;
                foreach ($journal['items'] as $item) {
                    if (($item['kind'] ?? null) === 'remove' && str_starts_with($item['path'], $directory['path'] . '/')) {
                        $ownsRemoval = true;
                    }
                }
                if (!$ownsRemoval) {
                    throw new \RuntimeException('The interrupted site initialization journal contains an unowned retired directory.');
                }
                $seenDirectories[$directory['path']] = true;
            }
        }
        $directories = [];
        foreach ($journal['created_directories'] as $directory) {
            if (!is_string($directory) || $directory === '.' || $directory === '.waaseyaa' || isset($directories[$directory])) {
                throw new \RuntimeException('The interrupted site initialization journal contains an invalid target directory.');
            }
            $this->assertSafeTarget($directory . '/placeholder');
            $ownedAncestor = false;
            foreach (array_keys($paths) as $path) {
                if (str_starts_with($path, $directory . '/')) {
                    $ownedAncestor = true;
                    break;
                }
            }
            if (!$ownedAncestor) {
                throw new \RuntimeException('The interrupted site initialization journal contains an unowned target directory.');
            }
            $directories[$directory] = true;
        }
    }

    private function cleanupOrphanControlResidue(): bool
    {
        $directory = $this->absolute('.waaseyaa');
        $entries = scandir($directory);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect site initialization control state.');
        }
        $cleaned = false;
        foreach ($entries as $entry) {
            $path = $directory . '/' . $entry;
            if (preg_match('/^site-init-(?:stage|backup)-[a-f0-9]{24}$/D', $entry) === 1) {
                if (is_link($path) || !is_dir($path)) {
                    throw new SiteInitializationCollisionException("Unsafe site initialization residue: .waaseyaa/{$entry}");
                }
                $this->removeControlTree($path);
                $cleaned = true;
            } elseif (preg_match('/^site-init\.transaction\.json\.tmp-[a-f0-9]{12}$/D', $entry) === 1) {
                $this->assertRegularOwnedFile($path, '.waaseyaa/' . $entry);
                if (!unlink($path)) {
                    throw new \RuntimeException("Unable to remove site initialization residue: .waaseyaa/{$entry}");
                }
                $this->syncDirectory($directory);
                $cleaned = true;
            }
        }

        return $cleaned;
    }

    private function removeControlTree(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to clean a linked transaction root.');
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Refusing to clean a linked transaction artifact.');
            }
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new \RuntimeException('Unable to clean a transaction artifact.');
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to clean a transaction directory.');
        }
        $this->syncDirectory(dirname($path));
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $items = scandir($directory);

        return $items === ['.', '..'];
    }

    private function injectFault(string $stage, int $index, string $path): void
    {
        if ($this->faultInjector !== null) {
            ($this->faultInjector)($stage, $index, $path);
        }
    }
}
