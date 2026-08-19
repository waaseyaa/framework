<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Activation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivationRequest;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Sync\ConfigSyncFile;

/**
 * Genesis (#2428) is the one activation that claims no CFG-03 verification, so
 * the guarantee that makes it safe is that it can carry nothing to verify.
 * These assertions pin that: every field capable of expressing a payload is
 * refused outright rather than ignored.
 */
#[CoversClass(ConfigurationActivationRequest::class)]
final class ConfigurationGenesisRequestTest extends TestCase
{
    #[Test]
    public function genesis_is_an_activation_that_carries_nothing(): void
    {
        $request = ConfigurationActivationRequest::genesis('install-init-fixture');

        self::assertTrue($request->isGenesis);
        // The verb is unchanged: genesis truthfully IS an activation, which is
        // why the ledger's operation CHECK did not need widening.
        self::assertSame('activate', $request->operation);
        self::assertSame([], $request->files());
        self::assertSame([], $request->tombstones());
        self::assertNull($request->expectedToken);
        self::assertNull($request->targetGenerationId);
        self::assertNull($request->verifiedBundle);
        self::assertFalse($request->completeReplacement);
    }

    #[Test]
    public function ordinary_activation_still_requires_a_verified_bundle(): void
    {
        $this->expectExceptionMessage('Ordinary configuration activation requires a verified CFG-03 bundle.');
        new ConfigurationActivationRequest(
            requestId: 'ordinary',
            expectedToken: null,
            files: [],
        );
    }

    #[Test]
    public function a_genesis_request_cannot_smuggle_content(): void
    {
        $file = ConfigSyncFile::writable(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['name' => 'x'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:' . str_repeat('a', 64),
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );

        foreach ([
            'files' => ['files' => [$file]],
            'tombstones' => ['tombstones' => ['system.site' => str_repeat('a', 64)]],
            'expected token' => ['expectedToken' => new ConfigurationActiveToken(str_repeat('b', 64), 1)],
            'target generation' => ['targetGenerationId' => str_repeat('c', 64)],
            'complete replacement' => ['completeReplacement' => true],
        ] as $label => $overrides) {
            try {
                new ConfigurationActivationRequest(
                    requestId: 'genesis-smuggle',
                    expectedToken: $overrides['expectedToken'] ?? null,
                    files: $overrides['files'] ?? [],
                    tombstones: $overrides['tombstones'] ?? [],
                    completeReplacement: $overrides['completeReplacement'] ?? false,
                    targetGenerationId: $overrides['targetGenerationId'] ?? null,
                    isGenesis: true,
                );
                self::fail(sprintf('Genesis accepted %s.', $label));
            } catch (\InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }
}
