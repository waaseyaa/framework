<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Blueprint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecision;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Blueprint\BlueprintLifecycle;
use Waaseyaa\SiteContract\Blueprint\BlueprintLifecycleResolver;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

final class BlueprintDecisionReceiptTest extends TestCase
{
    public function test_a_well_formed_receipt_parses_and_exposes_its_fields(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'approved'));

        self::assertSame(BlueprintDecision::Approved, $receipt->decision);
        self::assertSame($manifest->applicationBlueprint->digest, $receipt->blueprintDigest);
        self::assertSame($manifest->digest, $receipt->manifestDigest);
        self::assertSame('russell', $receipt->actor);
        self::assertSame('2026-09-01T12:00:00Z', $receipt->decidedAt);
        self::assertSame('manual-review', $receipt->mechanism);
        self::assertTrue($receipt->matches($manifest));
    }

    public function test_toArray_and_canonicalJson_are_consistent(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'approved'));

        $array = $receipt->toArray();
        self::assertSame(BlueprintDecisionReceipt::SCHEMA_ID, $array['schema']);
        self::assertSame(BlueprintDecisionReceipt::CONTRACT_VERSION, $array['version']);
        self::assertJson($receipt->canonicalJson());

        // A second receipt built from toArray() must produce the same canonical JSON.
        $roundTripped = BlueprintDecisionReceipt::fromArray($receipt->toArray());
        self::assertSame($receipt->canonicalJson(), $roundTripped->canonicalJson());
    }

    public function test_a_rejection_receipt_never_matches_as_approval(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'rejected'));

        self::assertSame(BlueprintDecision::Rejected, $receipt->decision);
        self::assertTrue($receipt->matches($manifest));
        self::assertNotSame(BlueprintDecision::Approved, $receipt->decision);
    }

    public function test_a_receipt_for_different_digests_does_not_match(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $data = $this->receiptData($manifest, 'approved');
        $data['manifest_digest'] = str_repeat('a', 64);
        $receipt = BlueprintDecisionReceipt::fromArray($data);

        self::assertFalse($receipt->matches($manifest));
    }

    #[DataProvider('invalidReceiptProvider')]
    public function test_invalid_receipts_fail_with_the_expected_code_and_path(array $overrides, string $expectedCode, string $expectedPath): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $data = array_merge($this->receiptData($manifest, 'approved'), $overrides);

        try {
            BlueprintDecisionReceipt::fromArray($data);
            self::fail('Expected receipt validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($expectedCode, $exception->violations[0]->code);
            self::assertSame($expectedPath, $exception->violations[0]->path);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidReceiptProvider(): iterable
    {
        yield 'unknown key' => [['extra' => 'x'], 'SITE001_UNKNOWN_KEY', '/extra'];
        yield 'actor is not a string' => [['actor' => null], 'SITE010_INVALID_TYPE', '/actor'];
        yield 'wrong schema' => [['schema' => 'waaseyaa.something_else'], 'SITE050_DECISION_RECEIPT_INVALID', '/schema'];
        yield 'wrong version' => [['version' => 2], 'SITE050_DECISION_RECEIPT_INVALID', '/version'];
        yield 'unknown decision' => [['decision' => 'maybe'], 'SITE050_DECISION_RECEIPT_INVALID', '/decision'];
        yield 'malformed blueprint digest' => [['blueprint_digest' => 'not-a-digest'], 'SITE014_INVALID_VALUE', '/blueprint_digest'];
        yield 'malformed decided_at' => [['decided_at' => '2026-09-01 12:00:00'], 'SITE050_DECISION_RECEIPT_INVALID', '/decided_at'];
        yield 'empty actor' => [['actor' => ''], 'SITE012_EMPTY_VALUE', '/actor'];
        yield 'mechanism is not a string' => [['mechanism' => null], 'SITE010_INVALID_TYPE', '/mechanism'];
        yield 'empty mechanism' => [['mechanism' => ''], 'SITE012_EMPTY_VALUE', '/mechanism'];
    }

    public function test_resolver_returns_proposed_without_a_receipt(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $resolver = new BlueprintLifecycleResolver();

        self::assertSame(BlueprintLifecycle::Proposed, $resolver->resolve($manifest, null));
    }

    public function test_resolver_returns_approved_for_a_matching_approval(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'approved'));
        $resolver = new BlueprintLifecycleResolver();

        self::assertSame(BlueprintLifecycle::Approved, $resolver->resolve($manifest, $receipt));
    }

    public function test_resolver_returns_rejected_for_a_matching_rejection(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'rejected'));
        $resolver = new BlueprintLifecycleResolver();

        self::assertSame(BlueprintLifecycle::Rejected, $resolver->resolve($manifest, $receipt));
    }

    public function test_resolver_returns_proposed_when_a_blueprint_byte_changes_after_approval(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'approved'));

        $edited = str_replace('label: Article', 'label: Articles', $this->fixture('valid/minimal.yaml'));
        $editedManifest = new SiteManifestParser()->parse($edited);
        $resolver = new BlueprintLifecycleResolver();

        self::assertSame(BlueprintLifecycle::Proposed, $resolver->resolve($editedManifest, $receipt));
    }

    public function test_resolver_returns_proposed_when_only_application_name_changes_after_approval(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'approved'));

        $edited = str_replace('name: Minimal Blueprint Application', 'name: Renamed Application', $this->fixture('valid/minimal.yaml'));
        $editedManifest = new SiteManifestParser()->parse($edited);
        $resolver = new BlueprintLifecycleResolver();

        self::assertSame(BlueprintLifecycle::Proposed, $resolver->resolve($editedManifest, $receipt));
    }

    public function test_a_rejection_receipt_is_never_resolved_as_approved(): void
    {
        $manifest = $this->manifest('valid/minimal.yaml');
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest, 'rejected'));
        $resolver = new BlueprintLifecycleResolver();

        self::assertNotSame(BlueprintLifecycle::Approved, $resolver->resolve($manifest, $receipt));
    }

    public function test_resolver_throws_for_a_manifest_without_a_blueprint(): void
    {
        $manifest = new SiteManifestParser()->parse($this->fixture('valid/old-v1-without-blueprint.yaml'));
        $resolver = new BlueprintLifecycleResolver();

        $this->expectException(\LogicException::class);
        $resolver->resolve($manifest, null);
    }

    /** @return array<string, mixed> */
    private function receiptData(SiteManifest $manifest, string $decision): array
    {
        return [
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => $decision,
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'russell',
            'decided_at' => '2026-09-01T12:00:00Z',
            'mechanism' => 'manual-review',
        ];
    }

    private function manifest(string $relative): SiteManifest
    {
        return new SiteManifestParser()->parse($this->fixture($relative));
    }

    private function fixture(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Blueprint/' . $relative);
    }
}
