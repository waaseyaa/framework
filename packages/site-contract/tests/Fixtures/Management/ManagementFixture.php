<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Fixtures\Management;

use Waaseyaa\SiteContract\ApplicationIdentity;
use Waaseyaa\SiteContract\Capability\CapabilityDeclaration;
use Waaseyaa\SiteContract\Capability\CapabilityState;
use Waaseyaa\SiteContract\FrameworkIdentity;
use Waaseyaa\SiteContract\Management\ManagementInventoryInterface;
use Waaseyaa\SiteContract\Management\ManagementManifest;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\Management\ManagementOperation;
use Waaseyaa\SiteContract\Management\ManagementVerificationResult;
use Waaseyaa\SiteContract\SiteManifest;

/** Synthetic contract fixtures: not product integration or deployment evidence. */
final class ManagementFixture
{
    public static function operation(string $id = 'forms.createDraft'): array
    {
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
            'properties' => new \stdClass(), 'additionalProperties' => false];

        return ['id' => $id, 'capability' => 'forms', 'binding' => ['transport' => 'api', 'name' => 'createForm'],
            'input_schema' => $schema, 'output_schema' => $schema, 'required_scopes' => ['forms:write'],
            'tenant_scope' => 'required', 'effects' => ['create'], 'idempotency' => 'key',
            'concurrency' => 'none', 'dry_run' => 'unsupported', 'audit' => 'durable',
            'approval' => 'not_required', 'verification' => ['contact-draft-retry', 'cross-tenant-denial']];
    }

    public static function document(?array $operation = null): array
    {
        $operation ??= self::operation();
        $id = $operation['id'];
        unset($operation['id']);

        return ['schema' => 'waaseyaa.management', 'version' => 1, 'application' => 'test-site',
            'operations' => [['id' => $id, 'state' => 'supported', 'contract' => $operation],
                ['id' => 'forms.delete', 'state' => 'unsupported', 'reason' => 'No supported deletion operation.']]];
    }

    public static function manifest(?array $document = null): ManagementManifest
    {
        return new ManagementManifestParser()->parse(json_encode($document ?? self::document(), JSON_THROW_ON_ERROR));
    }

    public static function site(CapabilityState $state = CapabilityState::Active): SiteManifest
    {
        return new SiteManifest(1, 1, new ApplicationIdentity('test-site', 'Test', 'APP_ORIGIN'),
            new FrameworkIdentity('exact-lock', str_repeat('a', 64)), [],
            ['forms' => new CapabilityDeclaration('forms', $state)], [], [], 'bin/site-verify', '{}', str_repeat('b', 64));
    }

    public static function inventory(array $operations, ?array $results = null, ?string $sourceDigest = null, ?string $siteDigest = null): ManagementInventoryInterface
    {
        $results ??= self::results($operations, $sourceDigest ?? str_repeat('c', 64), $siteDigest ?? str_repeat('b', 64));

        return new class($operations, $results, $sourceDigest ?? str_repeat('c', 64), $siteDigest ?? str_repeat('b', 64)) implements ManagementInventoryInterface {
            public function __construct(private array $operations, private array $results, private string $source, private string $siteDigest) {}
            public function sourceDigest(): string { return $this->source; }
            public function siteManifestDigest(): string { return $this->siteDigest; }
            public function operations(): iterable { yield from $this->operations; }
            public function verificationResults(): iterable { yield from $this->results; }
        };
    }

    /** @param list<ManagementOperation> $operations */
    public static function results(array $operations, ?string $source = null, ?string $site = null): array
    {
        $results = [];
        foreach ($operations as $operation) {
            foreach ($operation->metadata['verification'] as $id) {
                $results[] = new ManagementVerificationResult($operation->id, $id, $operation->digest(), true, 'synthetic-test:' . $id,
                    $source ?? str_repeat('c', 64), $site ?? str_repeat('b', 64));
            }
        }

        return $results;
    }
}
