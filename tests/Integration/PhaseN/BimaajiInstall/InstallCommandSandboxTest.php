<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\TargetFile;

/**
 * T014 — sandbox discipline (NFR-002). Installs a stub transformer that
 * tries to emit target paths outside the project root via three escape
 * vectors: absolute path, `..` traversal, and a long traversal back to
 * `/tmp`. Asserts the install command rejects each path BEFORE any
 * write happens (the rejection is logged + counted as skipped).
 *
 * **Test mechanism note for reviewers:** the malicious paths come from
 * a controlled stub transformer constructed by this test, not from the
 * production transformer set. We're proving the install command's
 * boundary defence, not pinning a real attack vector — the seven
 * shipped transformers all emit relative project-root paths.
 *
 * @api
 */
#[CoversNothing]
final class InstallCommandSandboxTest extends BimaajiInstallTestCase
{
    #[Test]
    public function rejectsAbsoluteTargetPath(): void
    {
        $this->seedTwoSkillFixtures();
        $absoluteTarget = sys_get_temp_dir() . '/waaseyaa_sandbox_escape_' . uniqid() . '.md';

        $transformer = $this->makeStubTransformer('absoluteattacker', $absoluteTarget);

        $tester = $this->makeTester(transformers: [$transformer]);
        $tester->execute(['--client=absoluteattacker', '--force']);

        self::assertFileDoesNotExist($absoluteTarget);
        $output = $tester->getOutput();
        self::assertStringContainsString('rejected suspicious target path', $output);
        self::assertStringContainsString('Client absoluteattacker: 0 written, 0 unchanged, 1 skipped.', $output);
    }

    #[Test]
    public function rejectsTraversalTargetPath(): void
    {
        $this->seedTwoSkillFixtures();
        $traversal = '../escape.md';

        $transformer = $this->makeStubTransformer('traversalattacker', $traversal);

        $tester = $this->makeTester(transformers: [$transformer]);
        $tester->execute(['--client=traversalattacker', '--force']);

        self::assertFileDoesNotExist(dirname($this->tempDir) . '/escape.md');
        $output = $tester->getOutput();
        self::assertStringContainsString('rejected suspicious target path', $output);
        self::assertStringContainsString('Client traversalattacker: 0 written, 0 unchanged, 1 skipped.', $output);
    }

    #[Test]
    public function rejectsDeepTraversalTargetPath(): void
    {
        $this->seedTwoSkillFixtures();
        $deep = '../../../etc/waaseyaa-escape.md';

        $transformer = $this->makeStubTransformer('deepattacker', $deep);

        $tester = $this->makeTester(transformers: [$transformer]);
        $tester->execute(['--client=deepattacker', '--force']);

        self::assertFileDoesNotExist('/etc/waaseyaa-escape.md');
        $output = $tester->getOutput();
        self::assertStringContainsString('rejected suspicious target path', $output);
    }

    private function makeStubTransformer(string $clientId, string $maliciousPath): ClientTransformerInterface
    {
        return new class ($clientId, $maliciousPath) implements ClientTransformerInterface {
            public function __construct(
                private readonly string $clientId,
                private readonly string $maliciousPath,
            ) {}

            public function clientId(): string
            {
                return $this->clientId;
            }

            /**
             * @param list<ParsedSkill> $skills
             * @return list<TargetFile>
             */
            public function targetFiles(array $skills): array
            {
                return [new TargetFile(
                    path: $this->maliciousPath,
                    content: "Attempted sandbox escape — should never be written.\n",
                    sourceSkill: null,
                )];
            }
        };
    }
}
