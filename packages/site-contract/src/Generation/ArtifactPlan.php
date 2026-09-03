<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;

/**
 * The immutable output of one generation compiler (ADR-025 D-6.1).
 *
 * A plan is a pure function of its compiler's validated input plus that
 * compiler's own version. It contains no status, no diff, no filesystem
 * observation, and no reference to any project, so two runs of the same
 * compiler on the same input — on different machines, at different times —
 * produce byte-identical plans. Everything that requires looking at a project
 * belongs to the execution authority, never here.
 *
 * The plan carries the full, final bytes of every artifact its unit owns rather
 * than a digest of them, because a plan is the thing an operator reviews and an
 * apply later executes, and a digest-only plan cannot be applied in a second
 * process. Version 1 is therefore a text-artifact contract: content must be
 * valid UTF-8. A binary artifact would require version 2.
 *
 * A plan row's path, mode and extension region are exactly a generated
 * artifact's fields, so the rows are generated artifacts: introducing a second
 * artifact-row shape would put two authorities on one question this framework
 * already answers once.
 *
 * The plan does not carry the generated ownership document. That document is
 * composed by the transaction authority from the plan plus the carried roster,
 * and a plan that declared it would be claiming ownership of state it cannot see.
 *
 * Refusals here are uncoded on purpose: the coded generation-error family is
 * introduced by a later slice, and emitting a code from a path that cannot yet
 * honour it is exactly what the staged-activation constraints forbid.
 *
 * @api
 */
final readonly class ArtifactPlan
{
    public const string SCHEMA_ID = 'waaseyaa.artifact_plan';
    public const int CONTRACT_VERSION = 1;

    /** A generation unit id is one or more of these segments joined by a colon. */
    private const string UNIT_ID_SEGMENT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    private const int UNIT_ID_MAX_LENGTH = 128;

    public string $canonicalJson;
    public string $digest;

    /**
     * @param list<GeneratedArtifact> $artifacts every artifact this unit owns, sorted by path
     * @param list<string> $retires unit ids this plan retires, sorted and unique
     * @param list<ComposerProviderRegistration> $registrations sorted by fqcn then group
     * @param list<string> $companionTests artifact paths, sorted and unique
     * @param list<string> $schemaEffects reserved; sorted and unique
     * @param list<string> $configEffects reserved; sorted and unique
     */
    public function __construct(
        public string $generatorFqcn,
        public int $generatorVersion,
        public string $unitId,
        public GenerationUnitDisposition $disposition,
        public string $inputDigest,
        public array $artifacts,
        public array $retires = [],
        public array $registrations = [],
        public array $companionTests = [],
        public ArtifactSetEvolution $setEvolution = ArtifactSetEvolution::Frozen,
        public array $schemaEffects = [],
        public array $configEffects = [],
    ) {
        if ($generatorFqcn === '' || $generatorVersion < 1) {
            throw new \InvalidArgumentException('Artifact plan generator identity is invalid.');
        }
        self::assertUnitId($unitId);
        if (preg_match('/^[a-f0-9]{64}$/D', $inputDigest) !== 1) {
            throw new \InvalidArgumentException('Artifact plan input_digest must be 64 lowercase hex characters.');
        }

        // CanonicalJson encodes a keyed array as a JSON object and ksorts it,
        // so a member D-6.1 declares a list must actually be one: otherwise a
        // path-keyed or array_filter'ed row set passes every foreach-based
        // order check here and is then silently re-sorted at encode, which is
        // precisely what D-6.3 says a plan may not do.
        foreach ([
            'artifacts' => $artifacts,
            'retires' => $retires,
            'registrations' => $registrations,
            'companion_tests' => $companionTests,
            'schema_effects' => $schemaEffects,
            'config_effects' => $configEffects,
        ] as $member => $values) {
            self::assertList($values, $member);
        }

        $paths = [];
        foreach ($artifacts as $artifact) {
            $previous = $paths === [] ? null : $paths[array_key_last($paths)];
            if ($previous !== null && strcmp($previous, $artifact->path) >= 0) {
                throw new \InvalidArgumentException('Artifact plan artifacts must be sorted by path.');
            }
            if (preg_match('//u', $artifact->content) !== 1) {
                throw new \InvalidArgumentException("Artifact plan content must be valid UTF-8: {$artifact->path}");
            }
            $paths[] = $artifact->path;
        }

        foreach ($retires as $retired) {
            self::assertUnitId($retired);
        }
        self::assertSortedUnique($retires, 'retires');
        if (in_array($unitId, $retires, true)) {
            throw new \InvalidArgumentException("Artifact plan cannot retire the unit it supplies: {$unitId}");
        }

        $declaredFqcns = [];
        foreach ($registrations as $registration) {
            if (in_array($registration->fqcn, $declaredFqcns, true)) {
                throw new \InvalidArgumentException('Artifact plan registrations must declare each fqcn once.');
            }
            $declaredFqcns[] = $registration->fqcn;
        }
        $previousRegistration = null;
        foreach ($registrations as $registration) {
            if ($previousRegistration !== null && self::compareRegistrations($previousRegistration, $registration) >= 0) {
                throw new \InvalidArgumentException('Artifact plan registrations must be sorted by fqcn then group.');
            }
            $previousRegistration = $registration;
        }

        self::assertSortedUnique($companionTests, 'companion_tests');
        foreach ($companionTests as $companionTest) {
            if (!in_array($companionTest, $paths, true)) {
                throw new \InvalidArgumentException("Artifact plan companion test is not one of its artifacts: {$companionTest}");
            }
        }

        foreach (['schema_effects' => $schemaEffects, 'config_effects' => $configEffects] as $label => $effects) {
            foreach ($effects as $effect) {
                if ($effect === '') {
                    throw new \InvalidArgumentException("Artifact plan {$label} must not contain an empty entry.");
                }
            }
            self::assertSortedUnique($effects, $label);
        }

        $this->canonicalJson = CanonicalJson::encode($this->toArray());
        $this->digest = hash('sha256', $this->canonicalJson . "\n");
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'generator' => ['fqcn' => $this->generatorFqcn, 'version' => $this->generatorVersion],
            'unit' => ['id' => $this->unitId, 'disposition' => $this->disposition->value],
            'input_digest' => $this->inputDigest,
            'artifacts' => array_map(self::artifactRow(...), $this->artifacts),
            'retires' => $this->retires,
            'registrations' => array_map(
                static fn(ComposerProviderRegistration $registration): array => $registration->toArray(),
                $this->registrations,
            ),
            'companion_tests' => $this->companionTests,
            'set_evolution' => $this->setEvolution->value,
            'schema_effects' => $this->schemaEffects,
            'config_effects' => $this->configEffects,
        ];
    }

    /** @return array<string, string> */
    private static function artifactRow(GeneratedArtifact $artifact): array
    {
        $row = [
            'path' => $artifact->path,
            'mode' => sprintf('%04o', $artifact->mode),
            'content' => $artifact->content,
        ];
        if ($artifact->extensionRegion !== null) {
            $row['extension_region'] = $artifact->extensionRegion;
        }

        return $row;
    }

    private static function compareRegistrations(
        ComposerProviderRegistration $first,
        ComposerProviderRegistration $second,
    ): int {
        $byFqcn = strcmp($first->fqcn, $second->fqcn);
        if ($byFqcn !== 0) {
            return $byFqcn;
        }
        if ($first->group === $second->group) {
            return 0;
        }
        if ($first->group === null) {
            return -1;
        }
        if ($second->group === null) {
            return 1;
        }

        return strcmp($first->group, $second->group);
    }

    /** @param array<array-key, mixed> $values */
    private static function assertList(array $values, string $member): void
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException("Artifact plan {$member} must be a list.");
        }
    }

    /** @param list<string> $values */
    private static function assertSortedUnique(array $values, string $label): void
    {
        $previous = null;
        foreach ($values as $value) {
            if ($previous !== null && strcmp($previous, $value) >= 0) {
                throw new \InvalidArgumentException("Artifact plan {$label} must be sorted and unique.");
            }
            $previous = $value;
        }
    }

    private static function assertUnitId(string $id): void
    {
        if ($id === '' || strlen($id) > self::UNIT_ID_MAX_LENGTH) {
            throw new \InvalidArgumentException("Generation unit id is invalid: {$id}");
        }
        foreach (explode(':', $id) as $segment) {
            if (preg_match(self::UNIT_ID_SEGMENT, $segment) !== 1) {
                throw new \InvalidArgumentException("Generation unit id is invalid: {$id}");
            }
        }
    }
}
