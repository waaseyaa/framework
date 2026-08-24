<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\BimaajiInstall;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Command\BimaajiInstallCommand;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\SkillSetParser;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Io\StdinSource;
use Waaseyaa\CLI\Testing\CliTester;

/**
 * Shared scaffolding for the bimaaji:install integration tests.
 *
 * Each test gets:
 *  - A per-test temp directory that doubles as both the project root
 *    (chdir target so the install command's `getcwd()` lands inside it)
 *    and the skills source (`<tempDir>/skills/waaseyaa/<id>/SKILL.md`).
 *  - A fresh `BimaajiInstallCommand` constructed with the optional
 *    custom transformer set (defaults to the seven framework
 *    transformers).
 *  - A `CliTester` wrapping the install command's `HandlerCommand`
 *    pulled directly from `BimaajiServiceProvider::consoleCommands()`.
 *
 * @api
 */
abstract class BimaajiInstallTestCase extends TestCase
{
    protected string $tempDir = '';
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->originalCwd = getcwd() ?: null;
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_bimaaji_install_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
        mkdir($this->tempDir . '/skills/waaseyaa', 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            @chdir($this->originalCwd);
        }
        new Filesystem()->remove($this->tempDir);
    }

    protected function writeSkillFixture(string $id, string $contents): void
    {
        $dir = $this->tempDir . '/skills/waaseyaa/' . $id;
        mkdir($dir, 0o755, true);
        file_put_contents($dir . '/SKILL.md', $contents);
    }

    protected function seedTwoSkillFixtures(): void
    {
        $this->writeSkillFixture('alpha', <<<MD
            ---
            name: Skill Alpha
            description: First fixture
            ---

            # Alpha

            Body for alpha.
            MD);

        $this->writeSkillFixture('beta', <<<MD
            ---
            name: Skill Beta
            description: Second fixture
            ---

            # Beta

            Body for beta.
            MD);
    }

    /**
     * @param iterable<ClientTransformerInterface>|null $transformers Custom transformer set; null uses the framework default seven.
     */
    protected function makeTester(?iterable $transformers = null, ?StdinSource $stdin = null): CliTester
    {
        chdir($this->tempDir);

        $provider = new BimaajiServiceProvider();
        $definition = $this->findInstallDefinition($provider);

        $command = $transformers === null
            ? null
            : new BimaajiInstallCommand(
                transformers: $transformers,
                skillSetParser: new SkillSetParser($this->tempDir . '/skills/waaseyaa'),
            );

        return CliTester::for(
            definition: $definition,
            container: $this->makeContainer($command),
            stdin: $stdin,
        );
    }

    private function findInstallDefinition(BimaajiServiceProvider $provider): HandlerCommand
    {
        foreach ($provider->consoleCommands() as $command) {
            if ($command->name === 'bimaaji:install') {
                return $command;
            }
        }

        self::fail('bimaaji:install command definition not yielded by BimaajiServiceProvider.');
    }

    private function makeContainer(?BimaajiInstallCommand $override = null): ContainerInterface
    {
        $tempDir = $this->tempDir;

        return new class ($override, $tempDir) implements ContainerInterface {
            public function __construct(
                private readonly ?BimaajiInstallCommand $override,
                private readonly string $tempDir,
            ) {}

            public function get(string $id): mixed
            {
                if ($id !== BimaajiInstallCommand::class) {
                    throw new \RuntimeException("Container stub: unknown service id {$id}.");
                }

                if ($this->override !== null) {
                    return $this->override;
                }

                $provider = new \Waaseyaa\Bimaaji\BimaajiServiceProvider();
                $provider->setKernelContext(projectRoot: $this->tempDir, config: [], manifestFormatters: []);
                $provider->register();

                $resolved = $provider->resolve(BimaajiInstallCommand::class);
                \assert($resolved instanceof BimaajiInstallCommand);

                return $resolved;
            }

            public function has(string $id): bool
            {
                return $id === BimaajiInstallCommand::class;
            }
        };
    }

}
