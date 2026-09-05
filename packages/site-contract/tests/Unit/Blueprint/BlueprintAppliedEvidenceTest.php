<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Blueprint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SiteContract\Blueprint\ApplicationBlueprint;
use Waaseyaa\SiteContract\Blueprint\BlueprintAppliedEvidence;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecision;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Exception\SiteManifestValidationException;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

final class BlueprintAppliedEvidenceTest extends TestCase
{
    public function test_closed_applied_evidence_round_trips_and_matches_only_the_current_manifest(): void
    {
        $manifest = $this->manifest();
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest));
        $document = [
            'generator_feature' => ApplicationBlueprint::GENERATOR_FEATURE,
            'decision_receipt' => $receipt->toArray(),
        ];

        $evidence = BlueprintAppliedEvidence::fromArray($document, '.waaseyaa/generated.json');

        self::assertSame(ApplicationBlueprint::GENERATOR_FEATURE, $evidence->generatorFeature);
        self::assertSame($receipt->canonicalJson(), $evidence->decisionReceipt->canonicalJson());
        self::assertSame($document, $evidence->toArray());
        self::assertTrue($evidence->matches($manifest));

        $changed = new SiteManifestParser()->parse(str_replace(
            'name: Minimal Blueprint Application',
            'name: Renamed Application',
            $this->fixture(),
        ));
        self::assertFalse($evidence->matches($changed));
    }

    public function test_from_decision_receipt_normalizes_the_public_receipt_constructor(): void
    {
        $manifest = $this->manifest();
        $receipt = BlueprintDecisionReceipt::fromArray($this->receiptData($manifest));

        self::assertSame(
            [
                'generator_feature' => ApplicationBlueprint::GENERATOR_FEATURE,
                'decision_receipt' => $receipt->toArray(),
            ],
            BlueprintAppliedEvidence::fromDecisionReceipt($receipt)->toArray(),
        );

        $malformed = new BlueprintDecisionReceipt(
            BlueprintDecision::Approved,
            'not-a-digest',
            $manifest->digest,
            'russell',
            '2026-09-01T12:00:00Z',
            'manual-review',
        );
        try {
            BlueprintAppliedEvidence::fromDecisionReceipt($malformed);
            self::fail('Expected the public receipt value to be normalized through its parser.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame('SITE014_INVALID_VALUE', $exception->violations[0]->code);
            self::assertSame('/decision_receipt/blueprint_digest', $exception->violations[0]->path);
        }
    }

    #[DataProvider('invalidEvidenceProvider')]
    public function test_invalid_evidence_fails_closed(array $mutations, string $expectedCode, string $expectedPath): void
    {
        $manifest = $this->manifest();
        $document = [
            'generator_feature' => ApplicationBlueprint::GENERATOR_FEATURE,
            'decision_receipt' => $this->receiptData($manifest),
        ];
        foreach ($mutations as $path => $value) {
            if ($path === 'missing_generator_feature') {
                unset($document['generator_feature']);
            } elseif ($path === 'missing_decision_receipt') {
                unset($document['decision_receipt']);
            } elseif ($path === 'decision') {
                $document['decision_receipt']['decision'] = $value;
            } elseif ($path === 'receipt_extra') {
                $document['decision_receipt']['extra'] = $value;
            } else {
                $document[$path] = $value;
            }
        }

        try {
            BlueprintAppliedEvidence::fromArray($document, '.waaseyaa/generated.json');
            self::fail('Expected applied evidence validation to fail.');
        } catch (SiteManifestValidationException $exception) {
            self::assertSame($expectedCode, $exception->violations[0]->code);
            self::assertSame($expectedPath, $exception->violations[0]->path);
        }
    }

    public static function invalidEvidenceProvider(): iterable
    {
        yield 'unknown member' => [['extra' => true], 'SITE001_UNKNOWN_KEY', '/extra'];
        yield 'missing feature' => [['missing_generator_feature' => true], 'SITE011_REQUIRED_KEY', '/generator_feature'];
        yield 'missing receipt' => [['missing_decision_receipt' => true], 'SITE011_REQUIRED_KEY', '/decision_receipt'];
        yield 'wrong feature' => [['generator_feature' => 'site-application-blueprint-v2'], 'SITE014_INVALID_VALUE', '/generator_feature'];
        yield 'receipt is not a mapping' => [['decision_receipt' => 'approved'], 'SITE010_INVALID_TYPE', '/decision_receipt'];
        yield 'rejected receipt' => [['decision' => 'rejected'], 'SITE050_DECISION_RECEIPT_INVALID', '/decision_receipt/decision'];
        yield 'malformed nested receipt' => [['receipt_extra' => true], 'SITE001_UNKNOWN_KEY', '/decision_receipt/extra'];
    }

    /** @return array<string, mixed> */
    private function receiptData(SiteManifest $manifest): array
    {
        return [
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'russell',
            'decided_at' => '2026-09-01T12:00:00Z',
            'mechanism' => 'manual-review',
        ];
    }

    private function manifest(): SiteManifest
    {
        return new SiteManifestParser()->parse($this->fixture());
    }

    private function fixture(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Blueprint/valid/minimal.yaml');
    }
}
