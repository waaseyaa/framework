<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompiler;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\SiteArtifactRendererFactory;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Blueprint\BlueprintAppliedEvidence;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\Generation\ArtifactSetEvolution;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifest;
use Waaseyaa\SiteContract\SiteManifestParser;

/**
 * Static activation proof for FW-SITE-BLUEPRINT-01D-2's ADR-025 D-13 gate.
 * Runtime admission/refusal tests supply the behavioral half of the proof;
 * this class freezes who owns the gate, what it admits, and what compilation
 * is permitted to know.
 */
#[CoversNothing]
final class BlueprintCompilerActivationBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testEngineAloneOwnsTheClosedTwoCompilerAdditiveAdmission(): void
    {
        $authority = new \ReflectionClass(SiteInitializationService::class);
        self::assertSame(
            [SiteArtifactRenderer::class, ApplicationBlueprintCompiler::class],
            $authority->getConstant('ADDITIVE_COMPILERS'),
            'D-13 items 1, 2 and 6 admit the legacy root renderer and approved blueprint root compiler only.',
        );
        self::assertSame(
            ['packages/cli/src/Site/SiteInitializationService.php'],
            $this->productionFilesContainingIdentifier('ADDITIVE_COMPILERS'),
            'Eligibility is execution-authority policy; no compiler or dispatcher may own another list.',
        );
    }

    public function testBlueprintCompilerDeclaresTheExistingManagedRootIdentity(): void
    {
        $yaml = (string) file_get_contents($this->root . '/packages/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml');
        $manifest = new SiteManifestParser()->parse($yaml);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        self::assertSame(ApplicationBlueprintCompiler::class, $plan->generatorFqcn);
        self::assertSame('site', $plan->unitId);
        self::assertSame(GenerationUnitDisposition::Managed, $plan->disposition);
        self::assertSame(ArtifactSetEvolution::Additive, $plan->setEvolution);
    }

    public function testApprovalIsTypedAtEveryEngineEntryAndAbsentFromPureCompilation(): void
    {
        $authority = new \ReflectionClass(SiteInitializationService::class);
        foreach (['initialize', 'evaluate', 'apply'] as $methodName) {
            $parameters = $authority->getMethod($methodName)->getParameters();
            $receipt = array_values(array_filter($parameters, static fn(\ReflectionParameter $candidate): bool => $candidate->getName() === 'decisionReceipt'));
            self::assertCount(1, $receipt, $methodName);
            self::assertTrue($receipt[0]->allowsNull(), $methodName);
            self::assertSame(BlueprintDecisionReceipt::class, ($receipt[0]->getType() instanceof \ReflectionNamedType) ? $receipt[0]->getType()->getName() : null, $methodName);
        }

        $compile = new \ReflectionMethod(ApplicationBlueprintCompiler::class, 'compile');
        self::assertSame([SiteManifest::class], array_map(
            static fn(\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType ? $parameter->getType()->getName() : null,
            $compile->getParameters(),
        ));
        self::assertSame([], $this->blueprintSourcesContaining([
            BlueprintDecisionReceipt::class,
            BlueprintAppliedEvidence::class,
            SiteInitializationService::class,
        ]), 'Compilation remains approval-free and cannot call the project execution authority.');
    }

    public function testOneEngineGateBindsApprovalAndGen011AcrossEvaluationDryRunAndApply(): void
    {
        $authority = new \ReflectionClass(SiteInitializationService::class);
        $gate = $this->methodSource($authority->getMethod('blueprintEvidenceForPlan'));
        self::assertStringContainsString('BlueprintAppliedEvidence::fromDecisionReceipt', $gate);
        self::assertStringContainsString('->matches($manifest)', $gate);
        self::assertStringContainsString('GenerationErrorCode::UnauthorizedSetDelta', $gate);

        $prepare = $this->methodSource($authority->getMethod('prepareUnitPlan'));
        self::assertStringContainsString('blueprintEvidenceForPlan(', $prepare);
        foreach (['initialize', 'evaluate'] as $methodName) {
            self::assertStringContainsString('prepareUnitPlan(', $this->methodSource($authority->getMethod($methodName)), $methodName);
        }
        self::assertStringContainsString('blueprintEvidenceForPlan(', $this->methodSource($authority->getMethod('apply')), 'apply must refuse invalid approval before lock acquisition.');
        self::assertStringContainsString('prepareUnitPlan(', $this->methodSource($authority->getMethod('applyUnderLock')), 'controlled apply must repeat admission under its existing lock.');
    }

    public function testCliActivationHasClosedCompilerAndFeatureParticipants(): void
    {
        self::assertSame(
            [ApplicationBlueprintCompiler::GENERATOR_FEATURES[0]],
            SiteArtifactRendererFactory::advertisedGeneratorFeatures(),
        );
        self::assertSame([
            'packages/cli/src/Handler/SiteInitHandler.php',
            'packages/cli/src/Site/SiteDoctorService.php',
        ], $this->productionFilesContainingIdentifier('ApplicationBlueprintCompilerFactory', excludeBlueprintDirectory: true));
        self::assertSame([
            'packages/cli/src/Site/SiteArtifactRendererFactory.php',
            'packages/cli/src/Site/SiteInitializationService.php',
        ], $this->productionFilesContainingIdentifier('ApplicationBlueprintCompiler', excludeBlueprintDirectory: true));
    }

    public function testCompilerAndEmittersMakeNoFilesystemClockEnvironmentOrApprovalCalls(): void
    {
        $forbidden = [
            'date(', 'time(', 'microtime(', 'strtotime(',
            'getenv(', '$_ENV', '$_SERVER',
            'file_get_contents(', 'file_put_contents(', 'fopen(', 'is_file(', 'is_dir(', 'mkdir(', 'unlink(', 'scandir(', 'glob(',
            'random_bytes(', 'random_int(', 'uniqid(', 'rand(', 'mt_rand(',
        ];
        self::assertSame([], $this->blueprintSourcesContaining($forbidden), 'The compiler and emitters remain pure functions of their inputs (ADR-025 D-8 and D-13 item 1).');
    }

    /** @return list<string> */
    private function productionFilesContainingIdentifier(string $identifier, bool $excludeBlueprintDirectory = false): array
    {
        $matches = [];
        foreach ($this->phpFiles($this->root . '/packages') as $file) {
            $relative = substr($file, strlen($this->root) + 1);
            if ($excludeBlueprintDirectory && str_starts_with($relative, 'packages/cli/src/Site/Blueprint/')) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === $identifier) {
                    $matches[] = $relative;
                    break;
                }
            }
        }
        sort($matches, SORT_STRING);

        return $matches;
    }

    /** @param list<string> $needles @return list<string> */
    private function blueprintSourcesContaining(array $needles): array
    {
        $matches = [];
        $root = $this->root . '/packages/cli/src/Site/Blueprint';
        foreach ($this->phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            foreach ($needles as $needle) {
                // Word-boundary-aware for a bare function-call token (everything
                // except the superglobals): a plain str_contains() on 'date('
                // also matches '...validate(' (#2788, GovernanceProviderEmitter's
                // real WorkflowValidator::validate() call), a false positive with
                // no filesystem/clock/environment access. Requiring the character
                // before the token not be an identifier character keeps every
                // genuine bare call (date(), time(), rand(), ...) caught.
                $found = str_ends_with($needle, '(')
                    ? preg_match('/(?<![A-Za-z0-9_])' . preg_quote($needle, '/') . '/', $source) === 1
                    : str_contains($source, $needle);
                if ($found) {
                    $matches[] = substr($file, strlen($this->root) + 1) . ' -> ' . $needle;
                }
            }
        }

        return $matches;
    }

    private function methodSource(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        return implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** @return list<string> */
    private function phpFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), '/src/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
