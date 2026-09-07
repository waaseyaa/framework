<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\CLI\Command\AiVerifyCommand;
use Waaseyaa\Bimaaji\Command\BimaajiInstallCommand;
use Waaseyaa\Bimaaji\Install\InstalledManifest;
use Waaseyaa\Bimaaji\Install\VerifyFindingCode;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Testing\CliTester;

#[CoversClass(AiVerifyCommand::class)]
#[CoversClass(InstalledManifest::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\GeneratedStateVerifier::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\InstallPathSandbox::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\VerifyReport::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\VerifyFinding::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\VerifyTargetStatus::class)]
#[CoversClass(\Waaseyaa\Bimaaji\Install\InstalledManifestReadResult::class)]

final class AiVerifyCommandTest extends TestCase
{
    private string $tempDir = '';
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: null;
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_ai_verify_' . uniqid();
        mkdir($this->tempDir . '/skills/alpha', 0o755, true);
        mkdir($this->tempDir . '/skills/beta', 0o755, true);
        $this->writeSkill('alpha', "---\nname: Skill Alpha\ndescription: First fixture\n---\n\n# Alpha\n\nBody for alpha.");
        $this->writeSkill('beta', "---\nname: Skill Beta\ndescription: Second fixture\n---\n\n# Beta\n\nBody for beta.");
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            @chdir($this->originalCwd);
        }
        new Filesystem()->remove($this->tempDir);
    }

    #[Test]
    public function theRegisteredCommandVerifiesAFreshInstall(): void
    {
        $this->install(['--client=cursor', '--force']);
        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('ai:verify: manifest ok', $tester->getOutput());
        self::assertStringContainsString('ai:verify: ok', $tester->getOutput());
    }

    #[Test]
    public function jsonOutputIsDeterministicOnSuccess(): void
    {
        $this->install(['--client=cursor', '--force']);
        $first = $this->verifyTester();
        $first->execute(['--json']);
        self::assertSame(0, $first->getExitCode(), $first->getOutput());

        $second = $this->verifyTester();
        $second->execute(['--json']);
        self::assertSame(0, $second->getExitCode(), $second->getOutput());
        self::assertSame($first->getOutput(), $second->getOutput());
        self::assertStringContainsString('"status": "ok"', $first->getOutput());
        self::assertStringNotContainsString('Body for alpha', $first->getOutput());
    }

    #[Test]
    public function outerHumanEditsOutsideMarkersPassAndReportWholefileOuterEdit(): void
    {
        $this->install(['--client=cursor', '--force']);
        $target = $this->tempDir . '/.cursorrules';
        $before = (string) file_get_contents($target);
        file_put_contents(
            $target,
            "MY PREAMBLE\n\n" . $before . "\nMY POSTSCRIPT\n",
        );

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('wholefile=outer_edit', $tester->getOutput());
        self::assertStringContainsString('managed_region=ok', $tester->getOutput());
        self::assertStringNotContainsString(VerifyFindingCode::TargetWholefileDrift->value, $tester->getOutput());
    }

    #[Test]
    public function managedRegionDriftFailsVerification(): void
    {
        $this->install(['--client=cursor', '--force']);
        $target = $this->tempDir . '/.cursorrules';
        $contents = (string) file_get_contents($target);
        $contents = str_replace('Body for alpha.', 'Tampered managed body.', $contents);
        file_put_contents($target, $contents);

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::TargetManagedRegionDrift->value, $tester->getOutput());
    }

    #[Test]
    public function aMissingManifestFailsWithADistinctCode(): void
    {
        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestMissing->value, $tester->getOutput());
    }

    #[Test]
    public function aMalformedManifestFailsWithADistinctCode(): void
    {
        $this->writeManifest('{ not json');

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestMalformed->value, $tester->getOutput());
    }

    #[Test]
    public function aFutureSchemaManifestFailsWithADistinctCode(): void
    {
        $this->writeManifest(json_encode([
            'schema_version' => InstalledManifest::SCHEMA_VERSION + 1,
            'clients' => ['cursor' => ['targets' => [['path' => '.cursorrules', 'sha1' => str_repeat('a', 40)]]]],
        ], JSON_THROW_ON_ERROR));

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestUnsupportedSchema->value, $tester->getOutput());
    }

    #[Test]
    public function aManifestEscapeTargetLeavesAnOutsideSentinelUntouched(): void
    {
        $this->install(['--client=cursor', '--force']);
        $outside = sys_get_temp_dir() . '/waaseyaa_ai_verify_sentinel_' . uniqid() . '.md';
        file_put_contents($outside, "sentinel bytes\n");
        $sentinelSha1 = sha1((string) file_get_contents($outside));

        $manifestPath = $this->tempDir . '/' . InstalledManifest::RELATIVE_PATH;
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['clients']['cursor']['targets'][] = ['path' => '../' . basename($outside), 'sha1' => $sentinelSha1];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestMalformed->value, $tester->getOutput());
        self::assertSame("sentinel bytes\n", file_get_contents($outside));
        @unlink($outside);
    }

    #[Test]
    public function aSymlinkedTargetIsRejectedWithoutTouchingTheSentinel(): void
    {
        $this->install(['--client=cursor', '--force']);
        $outside = sys_get_temp_dir() . '/waaseyaa_ai_verify_link_sentinel_' . uniqid() . '.md';
        file_put_contents($outside, "linked sentinel\n");

        $link = $this->tempDir . '/.cursorrules';
        unlink($link);
        self::assertTrue(symlink($outside, $link), 'POSIX symlink required for this proof.');

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestTargetEscapes->value, $tester->getOutput());
        self::assertSame("linked sentinel\n", file_get_contents($outside));
        @unlink($outside);
    }

    #[Test]
    public function duplicateManifestOwnershipFailsStrictValidation(): void
    {
        $this->install(['--client=cursor', '--force']);
        $manifestPath = $this->tempDir . '/' . InstalledManifest::RELATIVE_PATH;
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['clients']['codex'] = [
            'targets' => $manifest['clients']['cursor']['targets'],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestMalformed->value, $tester->getOutput());
    }

    #[Test]
    public function aSymlinkedManifestIsRejectedWithoutReadingOutsideContent(): void
    {
        $outside = sys_get_temp_dir() . '/waaseyaa_ai_verify_manifest_sentinel_' . uniqid() . '.json';
        file_put_contents($outside, '{"schema_version":1,"clients":{}}');

        $manifestDir = $this->tempDir . '/.waaseyaa';
        mkdir($manifestDir, 0o755, true);
        $link = $manifestDir . '/bimaaji-install.json';
        self::assertTrue(symlink($outside, $link), 'POSIX symlink required for this proof.');

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ManifestUnreadable->value, $tester->getOutput());
        self::assertStringNotContainsString('schema_version', $tester->getOutput());
        self::assertSame('{"schema_version":1,"clients":{}}', file_get_contents($outside));
        @unlink($outside);
    }

    #[Test]
    public function verifyingTwoSelectedClientsChecksOnlyThoseManifestRecords(): void
    {
        $this->install(['--client=cursor,codex', '--force']);
        $this->install(['--client=cursor']);

        $tester = $this->verifyTester();
        $tester->execute(['--client=cursor,codex']);

        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('ai:verify: clients codex, cursor', $tester->getOutput());
    }

    #[Test]
    public function unknownClientJsonReportIsBoundedWithoutConsoleMarkup(): void
    {
        $tester = $this->verifyTester();
        $tester->execute(['--client=not-a-client', '--json']);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString('"code": "unknown_client"', $tester->getOutput());
        self::assertStringNotContainsString('[error]', $tester->getOutput());
    }

    #[Test]
    public function requestedUnrecordedSupportedClientFailsWithClientNotInstalled(): void
    {
        $this->install(['--client=cursor', '--force']);

        $tester = $this->verifyTester();
        $tester->execute(['--client=claude']);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ClientNotInstalled->value, $tester->getOutput());
        self::assertStringContainsString('client claude:', $tester->getOutput());
    }

    #[Test]
    public function mixedRecordedAndUnrecordedSelectionFails(): void
    {
        $this->install(['--client=cursor', '--force']);

        $tester = $this->verifyTester();
        $tester->execute(['--client=cursor,claude']);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::ClientNotInstalled->value, $tester->getOutput());
        self::assertStringContainsString('wholefile=match', $tester->getOutput());
    }

    #[Test]
    public function canonicalEmptyManifestWithoutClientSelectionFails(): void
    {
        $this->writeManifest(InstalledManifest::empty()->toJson());

        $tester = $this->verifyTester();
        $tester->execute([]);

        self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
        self::assertStringContainsString(VerifyFindingCode::NoRecordedInstallation->value, $tester->getOutput());
        self::assertStringNotContainsString('ai:verify: ok', $tester->getOutput());
    }

    #[Test]
    public function oneShotTransformerGeneratorSupportsRealCommandVerification(): void
    {
        $this->install(['--client=cursor', '--force']);
        $transformers = (static function (): \Generator {
            yield new \Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer();
        })();
        $verifier = new \Waaseyaa\Bimaaji\Install\GeneratedStateVerifier($transformers, new \Waaseyaa\Bimaaji\Install\SkillSetParser($this->tempDir . '/skills', true));
        $command = new AiVerifyCommand(static function (?string $root, ?array $clients) use ($verifier): array {
            $report = $verifier->verify($root, $clients);

            return ['exit_code' => $report->isSuccess() ? 0 : 1, 'json' => $report->toJson(), 'lines' => $report->humanLines()];
        });
        $tester = CliTester::for($this->commandDefinition('ai:verify'), $this->containerFor($command));
        $tester->execute(['--json']);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
    }

    #[Test]
    public function retiredLinkedTargetIsRefusedWithoutDisclosure(): void
    {
        $this->install(['--client=cursor', '--force']);
        $outside = sys_get_temp_dir() . '/waaseyaa_verify_external_' . uniqid();
        file_put_contents($outside, 'external-private-sentinel');
        try {
            symlink($outside, $this->tempDir . '/retired.md');
            $manifest = InstalledManifest::load($this->tempDir);
            $targets = $manifest->targetsFor('cursor');
            $targets['retired.md'] = sha1('external-private-sentinel');
            file_put_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH, $manifest->withClient('cursor', $targets)->toJson());
            $tester = $this->verifyTester();
            $tester->execute(['--json']);
            self::assertSame(1, $tester->getExitCode(), $tester->getOutput());
            self::assertStringContainsString('manifest_target_escapes', $tester->getOutput());
            self::assertStringNotContainsString('external-private-sentinel', $tester->getOutput());
            self::assertSame('external-private-sentinel', file_get_contents($outside));
        } finally {
            unlink($outside);
        }
    }

    #[Test]
    public function publicVerifierRefusesUnknownClientFilter(): void
    {
        $this->install(['--client=cursor', '--force']);
        $verifier = new \Waaseyaa\Bimaaji\Install\GeneratedStateVerifier(
            [new \Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer()],
            new \Waaseyaa\Bimaaji\Install\SkillSetParser($this->tempDir . '/skills', true),
        );
        $result = $verifier->verify($this->tempDir, ['unknown']);
        self::assertFalse($result->isSuccess());
        self::assertSame(VerifyFindingCode::UnknownClient, $result->findings[0]->code);
    }

    #[Test]
    public function missingRecordedFileCannotVerify(): void
    {
        $this->install(['--client=cursor', '--force']);
        unlink($this->tempDir . '/.cursorrules');
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('target_missing', $tester->getOutput());
    }

    #[Test]
    public function retiredFileAndMissingCurrentOwnershipAreReportedSeparately(): void
    {
        $this->install(['--client=cursor', '--force']);
        file_put_contents($this->tempDir . '/retired.md', 'private retired content');
        $manifest = InstalledManifest::empty()->withClient('cursor', ['retired.md' => sha1('private retired content')]);
        file_put_contents($this->tempDir . '/' . InstalledManifest::RELATIVE_PATH, $manifest->toJson());
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('target_retired_present', $tester->getOutput());
        self::assertStringContainsString('target_unrecorded', $tester->getOutput());
        self::assertStringNotContainsString('private retired content', $tester->getOutput());
    }

    #[Test]
    public function oversizedTargetRefusesWithoutLeakingItsContent(): void
    {
        $this->install(['--client=cursor', '--force']);
        file_put_contents($this->tempDir . '/.cursorrules', str_repeat('private', 160000));
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('target_oversize', $tester->getOutput());
        self::assertStringNotContainsString('privateprivate', $tester->getOutput());
    }

    #[Test]
    public function markerlessTargetIsUnprovableNotFresh(): void
    {
        $this->install(['--client=cursor', '--force']);
        file_put_contents($this->tempDir . '/.cursorrules', 'private hand authored file');
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('target_managed_region_unprovable', $tester->getOutput());
        self::assertStringNotContainsString('private hand authored file', $tester->getOutput());
    }

    #[Test]
    public function missingCanonicalResourcesFailWithoutPrivateSourcePaths(): void
    {
        $this->install(['--client=cursor', '--force']);
        new Filesystem()->remove($this->tempDir . '/skills');
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('skill_source_failure', $tester->getOutput());
        self::assertStringNotContainsString($this->tempDir, $tester->getOutput());
    }

    #[Test]
    public function corruptCanonicalResourcesDoNotExposeDocumentContents(): void
    {
        $this->install(['--client=cursor', '--force']);
        $this->writeSkill('alpha', "---\nprivate: [broken\n");
        $tester = $this->verifyTester();
        $tester->execute(['--json']);
        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('skill_source_failure', $tester->getOutput());
        self::assertStringNotContainsString('private:', $tester->getOutput());
    }

    private function writeSkill(string $id, string $contents): void
    {
        file_put_contents($this->tempDir . '/skills/' . $id . '/SKILL.md', $contents);
    }

    /**
     * @param list<string> $args
     */
    private function install(array $args): void
    {
        chdir($this->tempDir);
        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext($this->tempDir, [
            'bimaaji' => ['skills_directory' => $this->tempDir . '/skills'],
        ], []);
        $provider->register();

        $tester = CliTester::for(
            definition: $this->commandDefinition('bimaaji:install'),
            container: $this->containerFor($provider->resolve(BimaajiInstallCommand::class)),
        );
        $tester->execute($args);
        self::assertSame(0, $tester->getExitCode(), $tester->getOutput());
    }

    private function verifyTester(): CliTester
    {
        chdir($this->tempDir);
        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext($this->tempDir, [
            'bimaaji' => ['skills_directory' => $this->tempDir . '/skills'],
        ], []);
        $provider->register();

        return CliTester::for(
            definition: $this->commandDefinition('ai:verify'),
            container: $this->containerFor($provider->resolve(AiVerifyCommand::class)),
        );
    }

    private function commandDefinition(string $name): HandlerCommand
    {
        foreach (new BimaajiServiceProvider()->consoleCommands() as $definition) {
            if ($definition->name === $name) {
                return $definition;
            }
        }

        self::fail(sprintf('BimaajiServiceProvider does not yield a %s command definition.', $name));
    }

    private function containerFor(object $handler): ContainerInterface
    {
        return new class ($handler) implements ContainerInterface {
            public function __construct(private readonly object $handler) {}

            public function get(string $id): mixed
            {
                if ($id !== $this->handler::class) {
                    throw new \RuntimeException("Container stub: unknown service id {$id}.");
                }

                return $this->handler;
            }

            public function has(string $id): bool
            {
                return $id === $this->handler::class;
            }
        };
    }

    private function writeManifest(string $contents): void
    {
        $path = $this->tempDir . '/' . InstalledManifest::RELATIVE_PATH;
        mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $contents);
    }
}
