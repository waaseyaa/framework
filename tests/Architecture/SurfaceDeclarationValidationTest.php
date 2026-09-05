<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * §4 validation fixtures for FW-DELIVERY-SURFACE-01 (#2901,
 * docs/specs/public-surface-declarations.md §4, §11): each case builds a
 * throwaway package tree and asserts the real generator (`bin/generate-surface-map`)
 * fails closed, naming the offender, for every row in the §4 table.
 */
#[CoversNothing]
final class SurfaceDeclarationValidationTest extends TestCase
{
    private string $repoRoot;
    private string $generator;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->generator = $this->repoRoot . '/bin/generate-surface-map';
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_surface_validate_' . uniqid('', true);
        new Filesystem()->mkdir($this->tmpRoot . '/docs');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function missing_a_scanned_contract_shape_with_no_declaration_fails_closed(): void
    {
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: ['entries' => []]);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "A scanned interface with no declaration must fail.\n{$out}");
        self::assertStringContainsString('missing', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\GammaContractInterface', $out);
    }

    #[Test]
    public function duplicate_fqcn_within_one_file_fails_closed(): void
    {
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'public'],
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'internal'],
            ],
        ]);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "A duplicate FQCN inside one file must fail.\n{$out}");
        self::assertStringContainsString('duplicate', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\GammaContractInterface', $out);
        self::assertStringContainsString('packages/gamma/public-surface.php', $out);
    }

    #[Test]
    public function orphaned_wrong_owner_fails_closed(): void
    {
        // gamma's own contract is declared correctly, but the file also
        // claims a symbol under a namespace gamma does not own.
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'public'],
                ['fqcn' => 'Waaseyaa\\NotOwned\\SomethingInterface', 'disposition' => 'public'],
            ],
        ]);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "A declaration outside the package's own PSR-4 prefix must fail.\n{$out}");
        self::assertStringContainsString('orphaned', $out);
        self::assertStringContainsString('Waaseyaa\\NotOwned\\SomethingInterface', $out);
    }

    #[Test]
    public function orphaned_does_not_load_fails_closed(): void
    {
        // Correctly owned by gamma's namespace, but no such type exists.
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'public'],
                ['fqcn' => 'Waaseyaa\\Gamma\\DoesNotExistInterface', 'disposition' => 'public'],
            ],
        ]);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "A declared FQCN with no matching source declaration must fail.\n{$out}");
        self::assertStringContainsString('orphaned', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\DoesNotExistInterface', $out);
        self::assertStringContainsString('does not load', $out);
    }

    #[Test]
    public function contradictory_two_packages_claim_the_same_fqcn_fails_closed(): void
    {
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'public'],
            ],
        ]);
        $this->writePackage('delta', 'Waaseyaa\\Delta\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'internal'],
            ],
        ], writeSourceFiles: false);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "Two packages claiming one FQCN must fail.\n{$out}");
        self::assertStringContainsString('contradictory', $out);
        self::assertStringContainsString('Waaseyaa\\Gamma\\GammaContractInterface', $out);
        self::assertStringContainsString('gamma', $out);
        self::assertStringContainsString('delta', $out);
    }

    #[Test]
    public function invalid_disposition_fails_closed(): void
    {
        $this->writePackage('gamma', 'Waaseyaa\\Gamma\\', declaration: [
            'entries' => [
                ['fqcn' => 'Waaseyaa\\Gamma\\GammaContractInterface', 'disposition' => 'featured'],
            ],
        ]);

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "An unknown disposition must fail.\n{$out}");
        self::assertStringContainsString('invalid', $out);
        self::assertStringContainsString('featured', $out);
    }

    #[Test]
    public function non_list_entries_fails_closed(): void
    {
        $dir = "{$this->tmpRoot}/packages/gamma";
        $fs = new Filesystem();
        $fs->mkdir("{$dir}/src");
        $fs->dumpFile("{$dir}/composer.json", json_encode([
            'name' => 'waaseyaa/gamma',
            'type' => 'library',
            'autoload' => ['psr-4' => ['Waaseyaa\\Gamma\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $fs->dumpFile(
            "{$dir}/src/GammaContractInterface.php",
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Waaseyaa\\Gamma;\n\ninterface GammaContractInterface\n{\n}\n",
        );
        // 'entries' is a keyed map, not a list — must be rejected rather than
        // silently accepted as "one entry".
        $fs->dumpFile(
            "{$dir}/public-surface.php",
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['entries' => ['Waaseyaa\\\\Gamma\\\\GammaContractInterface' => 'public']];\n",
        );

        [$exit, $out] = $this->generate();
        self::assertNotSame(0, $exit, "A keyed 'entries' map instead of a list must fail.\n{$out}");
        self::assertStringContainsString('invalid', $out);
        self::assertStringContainsString('list', $out);
    }

    /**
     * @param array{entries: list<array<string, string>>} $declaration
     */
    private function writePackage(string $short, string $nsPrefix, array $declaration, bool $writeSourceFiles = true): void
    {
        $fs = new Filesystem();
        $dir = "{$this->tmpRoot}/packages/{$short}";
        $fs->mkdir("{$dir}/src");
        $fs->dumpFile("{$dir}/composer.json", json_encode([
            'name' => "waaseyaa/{$short}",
            'type' => 'library',
            'autoload' => ['psr-4' => [$nsPrefix => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        // Every package used in these fixtures gets exactly one real contract
        // shape under its own namespace so the "missing" case has something
        // to discover, and the other cases have a valid baseline entry.
        $ownContractName = ucfirst($short) . 'ContractInterface';
        if ($writeSourceFiles) {
            $fs->dumpFile(
                "{$dir}/src/{$ownContractName}.php",
                "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . rtrim($nsPrefix, '\\') . ";\n\ninterface {$ownContractName}\n{\n}\n",
            );
        }

        $fs->dumpFile(
            "{$dir}/public-surface.php",
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($declaration, true) . ";\n",
        );
    }

    /** @return array{int, string} */
    private function generate(): array
    {
        $process = new Process(
            [PHP_BINARY, $this->generator, '--root=' . $this->tmpRoot, '--stdout=php'],
            $this->repoRoot,
            null,
            null,
            120,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }
}
