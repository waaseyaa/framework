<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\ManifestShapeReader;

/**
 * Structural typing for {@see ArtifactApplyRequest::fromArray()} (#2789).
 *
 * ADR-025 D-6.5 makes the request a document a **later process** reads: a
 * digest-only plan cannot be applied in a second process, so the plan bytes
 * travel with it. Reading those bytes back is therefore part of the contract,
 * not each consumer's business — an ad-hoc `json_decode` walk per entrypoint
 * would be a second authority on what a plan is allowed to be, and a lenient
 * one, since none of them can restate the plan's own invariants.
 *
 * This reader owns *shape* only: closed member sets, required members, types,
 * and closed vocabularies. Every semantic invariant — path safety, artifact
 * sort order, UTF-8 content, unit-id grammar, registration uniqueness,
 * companion-test membership — stays with {@see ArtifactPlan} and
 * {@see GeneratedArtifact}, which are re-entered verbatim and whose refusal is
 * relayed with the offending pointer. There is one authority per question.
 *
 * It is a separate class rather than a static method on the readonly request
 * for the reason {@see \Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceiptParser}
 * already records: the constructor requires every field, so there is no
 * default-constructible instance to parse onto. It reuses the shared
 * `SITE0xx` structural codes for the same reason that parser does — one
 * closed-shape reading discipline, one set of pointers, no new vocabulary for
 * a question the existing one already answers.
 *
 * Not `@api`: invoked only by `ArtifactApplyRequest`'s named constructors.
 */
final class ArtifactApplyRequestParser
{
    use ManifestShapeReader;

    private const string ARTIFACT_MODE_GRAMMAR = '/^0[0-7]{3}$/D';

    /** @param array<string, mixed> $data */
    public function parse(array $data, string $source): ArtifactApplyRequest
    {
        $members = ['schema', 'version', 'plan', 'plan_digest', 'project_state_digest'];
        $row = $this->shape($data, $members, $members, '/', $source);

        if ($this->string($row['schema'], '/schema', $source) !== ArtifactApplyRequest::SCHEMA_ID) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/schema', 'Expected ' . ArtifactApplyRequest::SCHEMA_ID . '.');
        }
        if ($this->integer($row['version'], '/version', $source) !== ArtifactApplyRequest::CONTRACT_VERSION) {
            $this->fail($source, 'SITE003_UNSUPPORTED_SCHEMA_VERSION', '/version', 'The apply-request schema is not supported by this runtime.');
        }
        $plan = $this->plan($row['plan'], '/plan', $source);
        // Not derived from the plan: D-6.5 requires the digest the operator
        // reviewed to survive transport so the execution authority can refuse
        // a mismatch as GEN005 under its lock. Re-deriving it here would erase
        // the only evidence of transport corruption before apply sees it.
        $planDigest = $this->sha256($row['plan_digest'], '/plan_digest', $source);
        $projectStateDigest = $this->sha256($row['project_state_digest'], '/project_state_digest', $source);

        try {
            return new ArtifactApplyRequest($plan, $planDigest, $projectStateDigest);
        } catch (\InvalidArgumentException $exception) {
            $this->fail($source, 'SITE014_INVALID_VALUE', '/', $exception->getMessage(), $exception);
        }
    }

    private function plan(mixed $value, string $path, string $source): ArtifactPlan
    {
        $members = [
            'schema', 'version', 'generator', 'unit', 'input_digest', 'artifacts',
            'retires', 'registrations', 'companion_tests', 'set_evolution',
            'schema_effects', 'config_effects',
        ];
        $row = $this->shape($value, $members, $members, $path, $source);

        if ($this->string($row['schema'], $path . '/schema', $source) !== ArtifactPlan::SCHEMA_ID) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/schema', 'Expected ' . ArtifactPlan::SCHEMA_ID . '.');
        }
        if ($this->integer($row['version'], $path . '/version', $source) !== ArtifactPlan::CONTRACT_VERSION) {
            $this->fail($source, 'SITE003_UNSUPPORTED_SCHEMA_VERSION', $path . '/version', 'The artifact-plan schema is not supported by this runtime.');
        }
        $generator = $this->shape($row['generator'], ['fqcn', 'version'], ['fqcn', 'version'], $path . '/generator', $source);
        $unit = $this->shape($row['unit'], ['id', 'disposition'], ['id', 'disposition'], $path . '/unit', $source);
        $disposition = GenerationUnitDisposition::tryFrom($this->string($unit['disposition'], $path . '/unit/disposition', $source));
        if ($disposition === null) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/unit/disposition', 'Unknown generation unit disposition.');
        }
        $setEvolution = ArtifactSetEvolution::tryFrom($this->string($row['set_evolution'], $path . '/set_evolution', $source));
        if ($setEvolution === null) {
            $this->fail($source, 'SITE014_INVALID_VALUE', $path . '/set_evolution', 'Unknown artifact set evolution.');
        }

        // Every member is read before the guard below, because
        // SiteManifestShapeReader's own refusal IS an \InvalidArgumentException:
        // reading inside the guard would relabel a precise member pointer as an
        // opaque whole-plan refusal.
        $generatorFqcn = $this->string($generator['fqcn'], $path . '/generator/fqcn', $source);
        $generatorVersion = $this->positiveInteger($generator['version'], $path . '/generator/version', $source);
        $unitId = $this->string($unit['id'], $path . '/unit/id', $source);
        $inputDigest = $this->sha256($row['input_digest'], $path . '/input_digest', $source);
        $artifacts = $this->artifacts($row['artifacts'], $path . '/artifacts', $source);
        $retires = $this->stringList($row['retires'], $path . '/retires', $source);
        $registrations = $this->registrations($row['registrations'], $path . '/registrations', $source);
        $companionTests = $this->stringList($row['companion_tests'], $path . '/companion_tests', $source);
        $schemaEffects = $this->stringList($row['schema_effects'], $path . '/schema_effects', $source);
        $configEffects = $this->stringList($row['config_effects'], $path . '/config_effects', $source);

        try {
            return new ArtifactPlan(
                $generatorFqcn,
                $generatorVersion,
                $unitId,
                $disposition,
                $inputDigest,
                $artifacts,
                $retires,
                $registrations,
                $companionTests,
                $setEvolution,
                $schemaEffects,
                $configEffects,
            );
        } catch (\InvalidArgumentException $exception) {
            // The plan's own invariants — sort order, UTF-8 content, unit-id
            // grammar, registration and companion-test rules — are re-entered
            // verbatim rather than restated here.
            $this->fail($source, 'SITE014_INVALID_VALUE', $path, $exception->getMessage(), $exception);
        }
    }

    /** @return list<GeneratedArtifact> */
    private function artifacts(mixed $value, string $path, string $source): array
    {
        $artifacts = [];
        foreach ($this->list($value, $path, $source) as $index => $item) {
            $rowPath = $path . '/' . $index;
            $row = $this->shape($item, ['path', 'mode', 'content', 'extension_region'], ['path', 'mode', 'content'], $rowPath, $source);
            $mode = $this->string($row['mode'], $rowPath . '/mode', $source);
            if (preg_match(self::ARTIFACT_MODE_GRAMMAR, $mode) !== 1) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $rowPath . '/mode', 'Expected four-digit octal permission bits.');
            }

            $artifactPath = $this->string($row['path'], $rowPath . '/path', $source);
            $content = $this->content($row['content'], $rowPath . '/content', $source);
            $extensionRegion = array_key_exists('extension_region', $row)
                ? $this->string($row['extension_region'], $rowPath . '/extension_region', $source)
                : null;

            try {
                $artifacts[] = new GeneratedArtifact($artifactPath, $content, intval($mode, 8), $extensionRegion);
            } catch (\InvalidArgumentException $exception) {
                $this->fail($source, 'SITE014_INVALID_VALUE', $rowPath, $exception->getMessage(), $exception);
            }
        }

        return $artifacts;
    }

    /** @return list<ComposerProviderRegistration> */
    private function registrations(mixed $value, string $path, string $source): array
    {
        $registrations = [];
        foreach ($this->list($value, $path, $source) as $index => $item) {
            $rowPath = $path . '/' . $index;
            $row = $this->shape($item, ['fqcn', 'group'], ['fqcn'], $rowPath, $source);
            $registrations[] = new ComposerProviderRegistration(
                $this->string($row['fqcn'], $rowPath . '/fqcn', $source),
                array_key_exists('group', $row) ? $this->string($row['group'], $rowPath . '/group', $source) : null,
            );
        }

        return $registrations;
    }

    /**
     * Artifact content is transported bytes, not an authored label: it is
     * routinely newline-terminated and internally indented, so the shared
     * trimmed-string rule would reject every real artifact. Emptiness is still
     * refused here, and every other property of those bytes belongs to
     * {@see GeneratedArtifact}.
     */
    private function content(mixed $value, string $path, string $source): string
    {
        if (!is_string($value)) {
            $this->fail($source, 'SITE010_INVALID_TYPE', $path, 'Expected a string.');
        }
        if ($value === '') {
            $this->fail($source, 'SITE012_EMPTY_VALUE', $path, 'Expected non-empty artifact content.');
        }

        return $value;
    }
}
