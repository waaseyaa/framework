<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\ProjectInit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\ProjectInit\ProjectConfigSiteIdentity;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(ProjectConfigSiteIdentity::class)]
final class ProjectConfigSiteIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_project_config_identity_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.waaseyaa', 0o755, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function evaluatesTheCommittedSiteContractWithTheCanonicalCompiler(): void
    {
        $source = dirname(__DIR__, 4) . '/site-contract/resources/starters/community-events/v1.yaml';
        $bytes = (string) file_get_contents($source);
        file_put_contents($this->root . '/.waaseyaa/site.yaml', $bytes);
        $manifest = new SiteManifestParser()->parse($bytes, $source);
        $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);

        self::assertSame(
            ['manifest' => $manifest->digest, 'plan' => $plan->digest],
            new ProjectConfigSiteIdentity($this->root)->evaluate(),
        );
    }

    #[Test]
    public function refusesAnAbsentCommittedSiteContract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProjectConfigSiteIdentity($this->root)->evaluate();
    }
}
