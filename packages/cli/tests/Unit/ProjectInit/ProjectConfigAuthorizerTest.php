<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\ProjectInit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorizer;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\Config\Manifest\ConfigManifestEnvelopeFile;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSigningResult;
use Waaseyaa\Config\Manifest\ConfigSyncBundleManifest;
use Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope;
use Waaseyaa\Config\Schema\CanonicalConfigEncoder;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(ProjectConfigAuthorizer::class)]
#[CoversClass(\Waaseyaa\CLI\Handler\ProjectConfigAuthorizeHandler::class)]
final class ProjectConfigAuthorizerTest extends TestCase
{
    #[Test]
    public function signsExactGeneratedSyncBytesBeforeAnyProjectInitialization(): void
    {
        $manifest = new SiteManifestParser()->parse((string) file_get_contents(
            __DIR__ . '/../../../../site-contract/tests/Fixtures/Blueprint/valid/complete.yaml',
        ));
        $receipt = BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint?->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'test-reviewer',
            'decided_at' => '2026-09-08T00:00:00Z',
            'mechanism' => 'test',
        ]);
        $expectedPlan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
        $expected = array_values(array_filter(
            $expectedPlan->artifacts,
            static fn($artifact): bool => $artifact->path === 'config/sync/workflows.assignments.yml',
        ))[0]->content;
        $observedBytes = null;
        $stagedRoot = null;

        $authorizer = new ProjectConfigAuthorizer(function (
            string $syncPath,
            string $scope,
            int $sequence,
            array $evidence,
        ) use (&$observedBytes, &$stagedRoot): ConfigManifestSigningResult {
            $stagedRoot = dirname(dirname($syncPath));
            $observedBytes = file_get_contents($syncPath . '/workflows.assignments.yml');
            $envelope = $this->envelope($scope, $sequence, $evidence);
            ConfigManifestEnvelopeFile::write($syncPath, $envelope);

            return new ConfigManifestSigningResult(
                ConfigManifestEnvelopeFile::pathFor($syncPath),
                hash('sha256', $envelope->manifestBytes),
                $scope,
                $sequence,
                'cfg04:test',
                1,
                [],
            );
        });

        $authorization = $authorizer->authorize($manifest, $receipt);

        self::assertSame($expected, $observedBytes);
        self::assertSame($manifest->digest, $authorization->siteManifestDigest);
        self::assertSame($expectedPlan->digest, $authorization->sitePlanDigest);
        self::assertNotNull($stagedRoot);
        self::assertDirectoryDoesNotExist($stagedRoot);
    }


    #[Test]
    public function commandAuthorizesActualDocumentsAndRefusesMissingCustodyWithoutSuccessOutput(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_authorize_handler_' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        try {
            $yaml = (string) file_get_contents(__DIR__ . '/../../../../site-contract/tests/Fixtures/Blueprint/valid/complete.yaml');
            $manifest = new SiteManifestParser()->parse($yaml);
            $receipt = BlueprintDecisionReceipt::fromArray([
                'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
                'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
                'decision' => 'approved',
                'blueprint_digest' => $manifest->applicationBlueprint?->digest,
                'manifest_digest' => $manifest->digest,
                'actor' => 'test-reviewer', 'decided_at' => '2026-09-08T00:00:00Z', 'mechanism' => 'test',
            ]);
            file_put_contents($root . '/answers.yaml', $yaml);
            file_put_contents($root . '/receipt.json', $receipt->canonicalJson());
            $authorizer = new ProjectConfigAuthorizer(function (string $syncPath, string $scope, int $sequence, array $evidence): ConfigManifestSigningResult {
                $envelope = $this->envelope($scope, $sequence, $evidence);
                ConfigManifestEnvelopeFile::write($syncPath, $envelope);

                return new ConfigManifestSigningResult(ConfigManifestEnvelopeFile::pathFor($syncPath), hash('sha256', $envelope->manifestBytes), $scope, $sequence, 'cfg04:test', 1, []);
            });
            foreach ([false, true] as $absolute) {
                $options = ['answers' => ($absolute ? $root . '/' : '') . 'answers.yaml', 'decision-receipt' => 'receipt.json'];
                [$io, $out, $err] = $this->commandIo($options);
                $handler = new \Waaseyaa\CLI\Handler\ProjectConfigAuthorizeHandler($root, static fn() => $authorizer);
                self::assertSame(0, $handler->execute($io));
                $result = \Waaseyaa\CLI\ProjectInit\ProjectConfigAuthorization::fromJson($out->fetch());
                self::assertSame($manifest->digest, $result->siteManifestDigest);
                self::assertSame('', $err->fetch());
            }
            foreach ([[], ['answers' => 'missing.yaml', 'decision-receipt' => 'receipt.json'], ['answers' => 'answers.yaml', 'decision-receipt' => 'receipt.json']] as $options) {
                [$io, $out, $err] = $this->commandIo($options);
                self::assertSame(1, new \Waaseyaa\CLI\Handler\ProjectConfigAuthorizeHandler($root, static fn() => null)->execute($io));
                self::assertSame('', $out->fetch());
                self::assertStringContainsString('project:config:authorize refused:', $err->fetch());
            }
        } finally {
            foreach (glob($root . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    #[Test]
    public function commandOptionallyBindsCanonicalManifestAndPlanBeforeSuccessOutput(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_authorize_binding_' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        try {
            $yaml = (string) file_get_contents(__DIR__ . '/../../../../site-contract/tests/Fixtures/Blueprint/valid/complete.yaml');
            $manifest = new SiteManifestParser()->parse($yaml);
            $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
            $receipt = BlueprintDecisionReceipt::fromArray([
                'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
                'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
                'decision' => 'approved',
                'blueprint_digest' => $manifest->applicationBlueprint?->digest,
                'manifest_digest' => $manifest->digest,
                'actor' => 'test-reviewer',
                'decided_at' => '2026-09-08T00:00:00Z',
                'mechanism' => 'test',
            ]);
            file_put_contents($root . '/answers.yaml', $yaml);
            file_put_contents($root . '/receipt.json', $receipt->canonicalJson());

            $signingCalls = 0;
            $authorizer = new ProjectConfigAuthorizer(function (
                string $syncPath,
                string $scope,
                int $sequence,
                array $evidence,
            ) use (&$signingCalls): ConfigManifestSigningResult {
                ++$signingCalls;
                $envelope = $this->envelope($scope, $sequence, $evidence);
                ConfigManifestEnvelopeFile::write($syncPath, $envelope);

                return new ConfigManifestSigningResult(
                    ConfigManifestEnvelopeFile::pathFor($syncPath),
                    hash('sha256', $envelope->manifestBytes),
                    $scope,
                    $sequence,
                    'cfg04:test',
                    1,
                    [],
                );
            });
            $factoryCalls = 0;
            $handler = new \Waaseyaa\CLI\Handler\ProjectConfigAuthorizeHandler(
                $root,
                function () use (&$factoryCalls, $authorizer): ProjectConfigAuthorizer {
                    ++$factoryCalls;

                    return $authorizer;
                },
            );
            $required = ['answers' => 'answers.yaml', 'decision-receipt' => 'receipt.json'];

            [$legacyIo, $legacyOut, $legacyErr] = $this->commandIo($required);
            self::assertSame(0, $handler->execute($legacyIo));
            $legacyBytes = $legacyOut->fetch();
            self::assertSame('', $legacyErr->fetch());

            [$boundIo, $boundOut, $boundErr] = $this->commandIo($required + [
                'expected-site-manifest-digest' => $manifest->digest,
                'expected-site-plan-digest' => $plan->digest,
            ]);
            self::assertSame(0, $handler->execute($boundIo));
            self::assertSame($legacyBytes, $boundOut->fetch());
            self::assertSame('', $boundErr->fetch());
            self::assertSame(2, $factoryCalls);
            self::assertSame(2, $signingCalls);

            $preSigningRefusals = [
                ['expected-site-manifest-digest' => $manifest->digest],
                ['expected-site-plan-digest' => $plan->digest],
                ['expected-site-manifest-digest' => '', 'expected-site-plan-digest' => $plan->digest],
                ['expected-site-manifest-digest' => str_repeat('A', 64), 'expected-site-plan-digest' => $plan->digest],
                ['expected-site-manifest-digest' => $manifest->digest, 'expected-site-plan-digest' => str_repeat('0', 63)],
            ];
            foreach ($preSigningRefusals as $options) {
                [$io, $out, $err] = $this->commandIo($required + $options);
                self::assertSame(1, $handler->execute($io));
                self::assertSame('', $out->fetch());
                self::assertStringContainsString('project:config:authorize refused:', $err->fetch());
                self::assertSame(2, $factoryCalls);
                self::assertSame(2, $signingCalls);
            }

            foreach ([
                [str_repeat('0', 64), $plan->digest],
                [$manifest->digest, str_repeat('0', 64)],
            ] as [$expectedManifestDigest, $expectedPlanDigest]) {
                [$io, $out, $err] = $this->commandIo($required + [
                    'expected-site-manifest-digest' => $expectedManifestDigest,
                    'expected-site-plan-digest' => $expectedPlanDigest,
                ]);
                self::assertSame(1, $handler->execute($io));
                self::assertSame('', $out->fetch());
                self::assertStringContainsString('does not match the evaluated site manifest and plan', $err->fetch());
            }
            self::assertSame(4, $factoryCalls);
            self::assertSame(4, $signingCalls);
        } finally {
            foreach (glob($root . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($root);
        }
    }

    /** @param array<string,string> $options @return array{\Waaseyaa\CLI\Command\SymfonyCommandIO,\Symfony\Component\Console\Output\BufferedOutput,\Symfony\Component\Console\Output\BufferedOutput} */
    private function commandIo(array $options): array
    {
        $definitions = [];
        $values = [];
        foreach ($options as $name => $value) {
            $definitions[] = new \Symfony\Component\Console\Input\InputOption($name, null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED);
            $values['--' . $name] = $value;
        }
        $input = new \Symfony\Component\Console\Input\ArrayInput($values, new \Symfony\Component\Console\Input\InputDefinition($definitions));
        $out = new \Symfony\Component\Console\Output\BufferedOutput();
        $err = new \Symfony\Component\Console\Output\BufferedOutput();

        return [new \Waaseyaa\CLI\Command\SymfonyCommandIO($input, $out, $err), $out, $err];
    }

    /** @param array<string, string> $evidence */
    private function envelope(string $scope, int $sequence, array $evidence): SignedConfigManifestEnvelope
    {
        $document = [
            'bundle_scope' => $scope,
            'bundle_sequence' => $sequence,
            'canonical_profile' => CanonicalConfigEncoder::PROFILE_V1,
            'entries' => [],
            'format' => ConfigSyncBundleManifest::FORMAT_V1,
            'producer_evidence' => $evidence,
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
