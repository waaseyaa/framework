<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Generation;

use Waaseyaa\SiteContract\CanonicalJson;

/**
 * The captured precondition identity of one project (ADR-025 D-6.2): the closed
 * record of exactly what evaluation observed, and therefore exactly what may not
 * change under it.
 *
 * This value records an observation; it never performs one. Only the execution
 * authority observes a project, so only the execution authority constructs this
 * type — which is what keeps the plan contract a pure function of compiler input.
 *
 * Its digest is formed exactly as D-6.2 specifies: sha256 over the canonical
 * encoding of the closed document plus one trailing newline. It deliberately
 * matches D-6.3's plan-digest rule so both stale-plan identities share one
 * canonicalization protocol.
 *
 * @api
 */
final readonly class ProjectStateIdentity
{
    public const string SCHEMA_ID = 'waaseyaa.project_state';
    public const int CONTRACT_VERSION = 1;

    /** What a digest member records when the file it names is absent. */
    public const string ABSENT_DIGEST = '0000000000000000000000000000000000000000000000000000000000000000';

    public string $canonicalJson;
    public string $digest;

    /** @param list<ProjectStateTarget> $targets the union of the plan's artifact paths and every recorded path it supplies or retires, sorted by path */
    public function __construct(
        public string $generatedMetadataSha256,
        public string $manifestSha256,
        public string $composerJsonSha256,
        public array $targets = [],
    ) {
        foreach ([$generatedMetadataSha256, $manifestSha256, $composerJsonSha256] as $documentDigest) {
            if (preg_match('/^[a-f0-9]{64}$/D', $documentDigest) !== 1) {
                throw new \InvalidArgumentException('Project state document digests must be 64 lowercase hex characters.');
            }
        }
        // A keyed array encodes as a JSON object and is ksorted at encode, so
        // the member D-6.2 declares a list must actually be one -- see the
        // matching guard on the plan document.
        if (!self::isList($targets)) {
            throw new \InvalidArgumentException('Project state targets must be a list.');
        }
        $previous = null;
        foreach ($targets as $target) {
            if ($previous !== null && strcmp($previous, $target->path) >= 0) {
                throw new \InvalidArgumentException('Project state targets must be sorted by path.');
            }
            $previous = $target->path;
        }
        $this->canonicalJson = CanonicalJson::encode($this->toArray());
        $this->digest = hash('sha256', $this->canonicalJson . "\n");
    }

    /** @param array<array-key, mixed> $values */
    private static function isList(array $values): bool
    {
        return array_is_list($values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_ID,
            'version' => self::CONTRACT_VERSION,
            'generated_metadata_sha256' => $this->generatedMetadataSha256,
            'manifest_sha256' => $this->manifestSha256,
            'composer_json_sha256' => $this->composerJsonSha256,
            'targets' => array_map(
                static fn(ProjectStateTarget $target): array => $target->toArray(),
                $this->targets,
            ),
        ];
    }
}
