<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Preflight;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Exception\FieldAccessActivationBlocked;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightData;
use Waaseyaa\Entity\Preflight\FieldAccessPreflightResult;
use Waaseyaa\Foundation\Kernel\Preflight\FieldAccessActivationPreflight;

/**
 * #2171, second half: the activation guard must fail **closed** whenever the
 * preflight state cannot be verified.
 *
 * Most of this behaviour already existed and is pinned here as regression
 * protection — a missing, malformed, drifted, or checksum-broken artifact was
 * already refused. The genuine gap was narrower: `scanner_version` was parsed
 * out of the artifact and then **never compared to anything**. Because the
 * scanner generation determines *which tables participate in the fingerprint*
 * (v2 narrowed it to entity storage, #2143), an artifact produced by a
 * different generation describes a different sweep than the running framework
 * performs — and on a database where the two table sets happen to coincide,
 * its fingerprint matches and the boot accepts it. Provenance the code cannot
 * verify was being treated as verified.
 *
 * Note the checksum does not cover this: `scanner_version` is inside
 * `canonicalData()`, so a *hand-edited* value is caught — but an artifact
 * legitimately written by another generation is internally consistent and
 * passes every check that existed.
 */
#[CoversClass(FieldAccessActivationPreflight::class)]
final class FieldAccessActivationFailsClosedTest extends TestCase
{
    private string $projectRoot;
    private const string FRAMEWORK_VERSION = 'test-framework@abc123';
    private const string SCHEMA_FINGERPRINT = 'fingerprint-abc';

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_activation_' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/.waaseyaa', 0o755, true);
    }

    protected function tearDown(): void
    {
        $path = $this->projectRoot . '/.waaseyaa/field-access-preflight.json';
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($this->projectRoot . '/.waaseyaa')) {
            rmdir($this->projectRoot . '/.waaseyaa');
        }
        if (is_dir($this->projectRoot)) {
            rmdir($this->projectRoot);
        }
    }

    // ------------------------------------------------------------------
    // The positive control. Without this, every assertion below could pass
    // because the guard rejects everything unconditionally.
    // ------------------------------------------------------------------

    #[Test]
    public function a_current_clean_artifact_is_accepted(): void
    {
        $this->writeArtifact();

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);

        $this->expectNotToPerformAssertions();
    }

    // ------------------------------------------------------------------
    // The gap this issue fixes.
    // ------------------------------------------------------------------

    #[Test]
    public function an_artifact_from_an_older_scanner_generation_is_refused(): void
    {
        // Internally consistent, correct checksum, matching framework version
        // and fingerprint — and produced by a scanner whose sweep semantics
        // the running framework no longer performs.
        $this->writeArtifact(scannerVersion: 1);

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/scanner/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_artifact_from_a_newer_scanner_generation_is_refused(): void
    {
        // Equally unverifiable in the other direction: this framework cannot
        // know what a future generation recorded or omitted, so it must not
        // pretend to have checked.
        $this->writeArtifact(scannerVersion: FieldAccessPreflightData::CURRENT_SCANNER_VERSION + 1);

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/scanner/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    // ------------------------------------------------------------------
    // Pre-existing fail-closed behaviour, pinned so it cannot regress.
    // ------------------------------------------------------------------

    #[Test]
    public function a_missing_artifact_is_refused(): void
    {
        $this->expectException(FieldAccessActivationBlocked::class);

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function a_malformed_artifact_is_refused(): void
    {
        file_put_contents($this->path(), '{ this is not json');

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/malformed/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_artifact_for_a_different_schema_is_refused(): void
    {
        // The exact staleness that a schema change produces: the artifact
        // describes entities the live database no longer matches.
        $this->writeArtifact(schemaFingerprint: 'fingerprint-from-an-older-schema');

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/stale/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_artifact_for_a_different_framework_version_is_refused(): void
    {
        $this->writeArtifact(frameworkVersion: 'some-other-framework@999999');

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/stale/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_artifact_whose_checksum_was_tampered_with_is_refused(): void
    {
        $document = $this->document();
        $document['checksum'] = str_repeat('0', 64);
        file_put_contents($this->path(), json_encode($document, JSON_THROW_ON_ERROR));

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/checksum|readiness/i');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_artifact_claiming_ready_while_carrying_blockers_is_refused(): void
    {
        // The forgery that matters most: flipping `ready` to true by hand while
        // unclassified entries remain.
        $document = $this->document(unclassified: ['monitor_source|*|secret']);
        $document['ready'] = true;
        file_put_contents($this->path(), json_encode($document, JSON_THROW_ON_ERROR));

        $this->expectException(FieldAccessActivationBlocked::class);

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    #[Test]
    public function an_honest_not_ready_artifact_is_refused(): void
    {
        $this->writeArtifact(unclassified: ['monitor_source|*|secret']);

        $this->expectException(FieldAccessActivationBlocked::class);
        $this->expectExceptionMessageMatches('/unclassified=1/');

        $this->guard()->assertReady($this->projectRoot, self::FRAMEWORK_VERSION, self::SCHEMA_FINGERPRINT);
    }

    // ------------------------------------------------------------------

    private function guard(): FieldAccessActivationPreflight
    {
        return new FieldAccessActivationPreflight();
    }

    private function path(): string
    {
        return $this->projectRoot . '/.waaseyaa/field-access-preflight.json';
    }

    /** @param list<string> $unclassified */
    private function writeArtifact(
        string $frameworkVersion = self::FRAMEWORK_VERSION,
        string $schemaFingerprint = self::SCHEMA_FINGERPRINT,
        ?int $scannerVersion = null,
        array $unclassified = [],
    ): void {
        file_put_contents($this->path(), json_encode(
            $this->document($frameworkVersion, $schemaFingerprint, $scannerVersion, $unclassified),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param list<string> $unclassified
     * @return array<string, mixed>
     */
    private function document(
        string $frameworkVersion = self::FRAMEWORK_VERSION,
        string $schemaFingerprint = self::SCHEMA_FINGERPRINT,
        ?int $scannerVersion = null,
        array $unclassified = [],
    ): array {
        $data = new FieldAccessPreflightData(
            frameworkVersion: $frameworkVersion,
            schemaFingerprint: $schemaFingerprint,
            scannerVersion: $scannerVersion ?? FieldAccessPreflightData::CURRENT_SCANNER_VERSION,
            fields: ['monitor_source|*|key' => 'public:definition'],
            conflicts: [],
            unclassifiedEntries: $unclassified,
            v1Drivers: [],
            serializedEntities: [],
            legacyPayloads: [],
        );

        return FieldAccessPreflightResult::fromData($data)->toArray();
    }
}
