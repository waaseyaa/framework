<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\ProjectInit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\ProjectInit\ProjectConfigActivationResultParser;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\CanonicalConfigEncoder;

#[CoversClass(ProjectConfigAuthorization::class)]
#[CoversClass(ProjectConfigActivationResultParser::class)]
final class ProjectConfigAuthorizationTest extends TestCase
{
    #[Test]
    public function closedDocumentBindsExactSiteDigestsIntoSignedProducerEvidence(): void
    {
        $manifestDigest = str_repeat('a', 64);
        $planDigest = str_repeat('b', 64);
        $authorization = ProjectConfigAuthorization::issue(
            $manifestDigest,
            $planDigest,
            $this->envelope($manifestDigest, $planDigest),
        );

        $reparsed = ProjectConfigAuthorization::fromJson($authorization->canonicalJson());

        self::assertSame($manifestDigest, $reparsed->siteManifestDigest);
        self::assertSame($planDigest, $reparsed->sitePlanDigest);
        self::assertSame(ProjectConfigAuthorization::scope($manifestDigest, $planDigest), $reparsed->envelope->protectedHeader['bundle_scope']);
        self::assertSame(1, $reparsed->envelope->protectedHeader['bundle_sequence']);
    }

    #[Test]
    public function outerDigestTamperingCannotRebindTheSignedAuthorization(): void
    {
        $manifestDigest = str_repeat('a', 64);
        $planDigest = str_repeat('b', 64);
        $document = ProjectConfigAuthorization::issue(
            $manifestDigest,
            $planDigest,
            $this->envelope($manifestDigest, $planDigest),
        )->toArray();
        $document['site_plan_digest'] = str_repeat('c', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('evaluated site identity');
        ProjectConfigAuthorization::fromArray($document);
    }

    #[Test]
    public function activationCompletionRequiresClosedOutputBoundToTheSignedManifest(): void
    {
        $manifestDigest = str_repeat('a', 64);
        $planDigest = str_repeat('b', 64);
        $authorization = ProjectConfigAuthorization::issue(
            $manifestDigest,
            $planDigest,
            $this->envelope($manifestDigest, $planDigest),
        );
        $payload = (object) [
            'schema' => 'waaseyaa.project_config_activation_result',
            'version' => 1,
            'status' => 'completed',
            'request_id' => 'project-config-init-' . str_repeat('e', 32),
            'generation_id' => str_repeat('f', 64),
            'activation_sequence' => 2,
            'manifest_hash' => $authorization->bundleManifest->manifestHash,
            'errors' => [],
        ];
        $parser = new ProjectConfigActivationResultParser();

        self::assertTrue($parser->isCompleted($payload, $authorization));
        $payload->manifest_hash = str_repeat('0', 64);
        self::assertFalse($parser->isCompleted($payload, $authorization));
        $payload->manifest_hash = $authorization->bundleManifest->manifestHash;
        $payload->unknown = true;
        self::assertFalse($parser->isCompleted($payload, $authorization));
    }


    #[Test]
    public function childFailuresRequireClosedNonemptyErrorObjects(): void
    {
        $parser = new ProjectConfigActivationResultParser();
        $valid = ['schema' => 'waaseyaa.project_config_activation_result', 'version' => 1, 'status' => 'refused', 'errors' => [(object) ['message' => 'Wrong authority']]];
        self::assertSame('refused', $parser->failureStatus((object) $valid));
        $valid['status'] = 'uncertain';
        self::assertSame('uncertain', $parser->failureStatus((object) $valid));
        foreach ([[], ['message' => 'not a list'], [null], [(object) ['message' => ' ']], [(object) ['message' => 42]], [(object) ['message' => 'failure', 'success' => true]]] as $errors) {
            $invalid = $valid;
            $invalid['errors'] = $errors;
            self::assertNull($parser->failureStatus((object) $invalid));
        }
        $valid['unknown'] = true;
        self::assertNull($parser->failureStatus((object) $valid));
    }


    #[Test]
    public function authorizationRejectsMalformedTransportAndAmbiguousContractFields(): void
    {
        $authorization = ProjectConfigAuthorization::issue(str_repeat('a', 64), str_repeat('b', 64), $this->envelope(str_repeat('a', 64), str_repeat('b', 64)));
        foreach (['{', 'null', '[]', json_encode($authorization->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)] as $bytes) {
            try {
                ProjectConfigAuthorization::fromJson($bytes);
                self::fail('Malformed or noncanonical transport was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('JSON', $error->getMessage());
            }
        }
        foreach (['unknown' => true, 'version' => '1', 'schema' => 'other', 'site_manifest_digest' => str_repeat('A', 64), 'site_plan_digest' => null, 'envelope' => []] as $field => $value) {
            $document = $authorization->toArray();
            $document[$field] = $value;
            try {
                ProjectConfigAuthorization::fromArray($document);
                self::fail('Invalid contract field was accepted: ' . $field);
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Project configuration authorization', $error->getMessage());
            }
        }
    }

    private function envelope(string $manifestDigest, string $planDigest): SignedConfigManifestEnvelope
    {
        $document = [
            'bundle_scope' => ProjectConfigAuthorization::scope($manifestDigest, $planDigest),
            'bundle_sequence' => 1,
            'canonical_profile' => CanonicalConfigEncoder::PROFILE_V1,
            'entries' => [],
            'format' => ConfigSyncBundleManifest::FORMAT_V1,
            'producer_evidence' => ProjectConfigAuthorization::producerEvidence($manifestDigest, $planDigest),
            'registry_checksum' => 'sha256:' . str_repeat('d', 64),
            'required_package_contracts' => [],
            'schema_dialect' => 'waaseyaa.config-schema/1',
            'sync_format' => 'waaseyaa.config-sync/1',
        ];
        $manifest = ConfigSyncBundleManifest::fromCanonicalBytes(new CanonicalConfigEncoder()->encode($document, [
            'type' => 'object',
            'properties' => [
                'entries' => ['type' => 'object'],
                'producer_evidence' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'required_package_contracts' => ['type' => 'object'],
            ],
        ]));

        return SignedConfigManifestEnvelope::sign($manifest, new class implements ConfigManifestSignerInterface {
            public function algorithm(): string
            {
                return SignedConfigManifestEnvelope::ALGORITHM_V1;
            }

            public function trustKeyReference(): string
            {
                return 'cfg04:test';
            }

            public function sign(string $message): string
            {
                return str_repeat('s', SignedConfigManifestEnvelope::SIGNATURE_BYTES_V1);
            }
        });
    }
}
