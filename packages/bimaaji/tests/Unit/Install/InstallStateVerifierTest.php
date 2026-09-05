<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\ClaudeClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\CodexClientTransformer;
use Waaseyaa\Bimaaji\Install\InstallStateVerifier;
use Waaseyaa\Bimaaji\Install\InstalledManifest;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\PackagedSkillResources;
use Waaseyaa\Bimaaji\Install\SkillInventory;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

#[CoversClass(InstallStateVerifier::class)]
final class InstallStateVerifierTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_install_verifier_' . uniqid();
        mkdir($this->projectRoot, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->projectRoot);
    }

    #[Test]
    public function verifyClientReportsNoIssuesWhenInstalledBytesMatch(): void
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all());
        $transformer = new CodexClientTransformer();
        $this->writeInstalledTargets($transformer, $inventory);

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertSame([], $issues);
    }

    #[Test]
    public function verifyClientReportsMissingTargetsAsNotYetInstalled(): void
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all());
        $transformer = new CodexClientTransformer();

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertNotSame([], $issues);
        self::assertStringContainsString('not yet installed', $issues[0]);
        self::assertStringNotContainsString('Drift in managed region', implode("\n", $issues));
    }

    #[Test]
    public function verifyClientDoesNotMisdiagnoseMarkerlessGuidanceAsDrift(): void
    {
        $inventory = SkillInventory::fromSkills(InstallSkillFixtures::all());
        $transformer = new CodexClientTransformer();
        file_put_contents($this->projectRoot . '/AGENTS.md', "# Hand-authored AGENTS.md\n\nNo bimaaji markers here.\n");

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertContains(
            'Target AGENTS.md is unmanaged (hand-authored file at path).',
            $issues,
        );
        self::assertStringNotContainsString('Drift in managed region of AGENTS.md', implode("\n", $issues));
    }

    #[Test]
    public function verifyClientReportsTrueManagedRegionDrift(): void
    {
        $inventory = SkillInventory::fromSkills([InstallSkillFixtures::alpha()]);
        $transformer = new CodexClientTransformer();
        $this->writeInstalledTargets($transformer, $inventory);

        $path = $this->projectRoot . '/AGENTS.md';
        $contents = (string) file_get_contents($path);
        $inner = ManagedRegion::extract($contents);
        self::assertNotNull($inner);
        $tampered = str_replace($inner, $inner . "\n\nStale guidance inside the managed region.", $contents);
        file_put_contents($path, $tampered);

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertContains('Drift in managed region of AGENTS.md.', $issues);
    }

    #[Test]
    public function verifyClientReportsOwnedTargetsThatLostTheirManagedRegion(): void
    {
        $inventory = SkillInventory::fromSkills([InstallSkillFixtures::alpha()]);
        $transformer = new CodexClientTransformer();
        $this->writeInstalledTargets($transformer, $inventory);
        $this->writeManifest($transformer->clientId(), [
            'AGENTS.md' => sha1((string) file_get_contents($this->projectRoot . '/AGENTS.md')),
        ]);
        file_put_contents($this->projectRoot . '/AGENTS.md', "Hand-authored replacement without markers.\n");

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertContains('Owned target AGENTS.md lacks a managed region.', $issues);
        self::assertStringNotContainsString('Drift in managed region of AGENTS.md', implode("\n", $issues));
    }

    #[Test]
    public function verifyClientReportsStaleOwnedTargetsStillOnDisk(): void
    {
        $inventory = SkillInventory::fromSkills([InstallSkillFixtures::alpha()]);
        $transformer = new CodexClientTransformer();
        $this->writeInstalledTargets($transformer, $inventory);
        $stalePath = '.agents/skills/waaseyaa-skill-beta/SKILL.md';
        $this->writeManifest($transformer->clientId(), [
            'AGENTS.md' => sha1((string) file_get_contents($this->projectRoot . '/AGENTS.md')),
            $stalePath => sha1('retired skill bytes'),
        ]);
        $this->writeRelative($stalePath, ManagedRegion::wrap('Retired skill still on disk.'));

        $issues = (new InstallStateVerifier())->verifyClient(
            $this->projectRoot,
            $transformer,
            $inventory,
        );

        self::assertContains(
            sprintf('Stale owned target %s is still on disk.', $stalePath),
            $issues,
        );
    }

    #[Test]
    public function verifyClientReportsUnreadableTargets(): void
    {
        $inventory = SkillInventory::fromSkills([InstallSkillFixtures::alpha()]);
        $transformer = new CodexClientTransformer();
        $this->writeInstalledTargets($transformer, $inventory);

        $path = $this->projectRoot . '/AGENTS.md';
        chmod($path, 0o000);

        try {
            $issues = (new InstallStateVerifier())->verifyClient(
                $this->projectRoot,
                $transformer,
                $inventory,
            );
        } finally {
            chmod($path, 0o644);
        }

        self::assertContains('Cannot read expected target AGENTS.md.', $issues);
    }

    #[Test]
    public function codexAndClaudeEmitIdenticalPerSkillBytesForTheSameInventory(): void
    {
        $inventory = SkillInventory::fromParser(new SkillSetParser(PackagedSkillResources::directory()));
        $claudeBySkill = $this->skillFilesById((new ClaudeClientTransformer())->targetFiles($inventory->all()));
        $codexBySkill = $this->skillFilesById((new CodexClientTransformer())->targetFiles($inventory->all()));

        self::assertSame(array_keys($claudeBySkill), array_keys($codexBySkill));

        foreach ($claudeBySkill as $skillId => $claudeFile) {
            $codexFile = $codexBySkill[$skillId];
            self::assertSame(
                $claudeFile->content,
                $codexFile->content,
                sprintf('Per-skill bytes must match for %s.', $skillId),
            );
            self::assertSame(
                hash('sha256', $claudeFile->content),
                hash('sha256', $codexFile->content),
                sprintf('Per-skill sha256 must match for %s.', $skillId),
            );
            self::assertSame($claudeFile->sourceSkill, $codexFile->sourceSkill);
        }
    }

    /**
     * @param list<\Waaseyaa\Bimaaji\Install\TargetFile> $files
     * @return array<string, \Waaseyaa\Bimaaji\Install\TargetFile>
     */
    private function skillFilesById(array $files): array
    {
        $byId = [];
        foreach ($files as $file) {
            if ($file->sourceSkill === null) {
                continue;
            }
            $byId[$file->sourceSkill] = $file;
        }
        ksort($byId);

        return $byId;
    }

    private function writeInstalledTargets(
        CodexClientTransformer $transformer,
        SkillInventory $inventory,
    ): void {
        foreach ($transformer->targetFiles($inventory->all()) as $file) {
            $this->writeRelative($file->path, $file->content);
        }
    }

    private function writeRelative(string $relativePath, string $content): void
    {
        $absolute = $this->projectRoot . DIRECTORY_SEPARATOR . $relativePath;
        $directory = dirname($absolute);
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
        file_put_contents($absolute, $content);
    }

    /**
     * @param array<string, string> $targets
     */
    private function writeManifest(string $clientId, array $targets): void
    {
        $directory = $this->projectRoot . '/.waaseyaa';
        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }
        file_put_contents(
            $directory . '/bimaaji-install.json',
            InstalledManifest::empty()->withClient($clientId, $targets)->toJson(),
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @chmod($item->getPathname(), 0o644);
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
