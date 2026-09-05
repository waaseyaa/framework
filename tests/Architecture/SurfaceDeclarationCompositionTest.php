<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Acceptance proof for #2901 (FW-DELIVERY-SURFACE-01, contract
 * docs/specs/public-surface-declarations.md §11):
 *
 *   "Prove two independent package additions can be prepared and combined
 *    without hand-editing a common aggregate. The combined view includes both,
 *    and conflicting classification of one symbol fails closed."
 *
 * Each case builds a throwaway package tree with real `src/` contract shapes
 * and package-local `public-surface.php` declarations, then runs the real
 * generator against it via the documented root override. Nothing here reads or
 * writes docs/public-surface-map.*: that is the point.
 */
#[CoversNothing]
final class SurfaceDeclarationCompositionTest extends TestCase
{
    private string $repoRoot;
    private string $generator;
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->generator = $this->repoRoot . '/bin/generate-surface-map';
        self::assertFileExists($this->generator, 'bin/generate-surface-map must exist (FW-DELIVERY-SURFACE-01).');
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_surface_compose_' . uniqid('', true);
        new Filesystem()->mkdir($this->tmpRoot . '/docs');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function two_independent_package_additions_combine_without_touching_an_aggregate(): void
    {
        // Package A and package B are prepared independently: each adds one
        // contract shape and declares it in its OWN file. No aggregate exists
        // in either "PR"; the tmp tree has no docs/public-surface-map.* at all.
        $this->writePackage('alpha', 'Waaseyaa\\Alpha\\', [
            ['fqcn' => 'Waaseyaa\\Alpha\\AlphaContractInterface', 'disposition' => 'public', 'purpose' => 'Alpha seam'],
        ]);
        $this->writePackage('beta', 'Waaseyaa\\Beta\\', [
            ['fqcn' => 'Waaseyaa\\Beta\\BetaContractInterface', 'disposition' => 'internal'],
        ]);

        [$exit, $php] = $this->generate(['--stdout=php']);
        self::assertSame(0, $exit, "Composition of two independent declarations must succeed.\n{$php}");

        $composed = $this->evaluatePhpMap($php);
        self::assertSame('public', $composed['Waaseyaa\\Alpha\\AlphaContractInterface'] ?? null);
        self::assertSame('internal', $composed['Waaseyaa\\Beta\\BetaContractInterface'] ?? null);
        self::assertSame(
            array_keys($composed),
            (static function (array $keys): array {
                sort($keys, SORT_STRING);
                return $keys;
            })(array_keys($composed)),
            'Composed keys must be in stable strcmp order.',
        );

        // Determinism: a second generation is byte-identical.
        [, $again] = $this->generate(['--stdout=php']);
        self::assertSame($php, $again, 'Generating twice must yield identical bytes.');

        [$exitMd, $md] = $this->generate(['--stdout=md']);
        self::assertSame(0, $exitMd);
        self::assertStringContainsString('AlphaContractInterface', $md);
        self::assertStringContainsString('Alpha seam', $md);
        self::assertStringContainsString('BetaContractInterface', $md);
        // The human view must carry the disposition: an internal row rendered
        // indistinguishably from a public one would read as a commitment.
        self::assertStringContainsString('| `AlphaContractInterface` | interface | public | Alpha seam |', $md);
        self::assertStringContainsString('| `BetaContractInterface` | interface | internal | — |', $md);
    }

    #[Test]
    public function conflicting_classification_of_one_symbol_fails_closed(): void
    {
        // Both packages claim the SAME symbol with different dispositions.
        // Ownership belongs to alpha by PSR-4; beta's claim is both orphaned
        // and contradictory. Composition must refuse, naming both packages,
        // rather than letting either "win".
        $this->writePackage('alpha', 'Waaseyaa\\Alpha\\', [
            ['fqcn' => 'Waaseyaa\\Alpha\\SharedContractInterface', 'disposition' => 'public'],
        ]);
        $this->writePackage('beta', 'Waaseyaa\\Beta\\', [
            ['fqcn' => 'Waaseyaa\\Alpha\\SharedContractInterface', 'disposition' => 'internal'],
        ], extraSrc: false);

        [$exit, $out] = $this->generate(['--stdout=php']);
        self::assertNotSame(0, $exit, "Contradictory classification must fail closed.\n{$out}");
        self::assertStringContainsString('Waaseyaa\\Alpha\\SharedContractInterface', $out);
        self::assertStringContainsString('alpha', $out);
        self::assertStringContainsString('beta', $out);
    }

    #[Test]
    public function the_same_symbol_declared_twice_even_identically_fails_closed(): void
    {
        // One declaration plane means one declaration: an identical duplicate
        // across packages is still a contradiction of ownership.
        $this->writePackage('alpha', 'Waaseyaa\\Alpha\\', [
            ['fqcn' => 'Waaseyaa\\Alpha\\TwiceInterface', 'disposition' => 'public'],
        ]);
        $this->writePackage('beta', 'Waaseyaa\\Beta\\', [
            ['fqcn' => 'Waaseyaa\\Alpha\\TwiceInterface', 'disposition' => 'public'],
        ], extraSrc: false);

        [$exit, $out] = $this->generate(['--stdout=php']);
        self::assertNotSame(0, $exit, "Duplicate cross-package declaration must fail closed.\n{$out}");
        self::assertStringContainsString('Waaseyaa\\Alpha\\TwiceInterface', $out);
    }

    /**
     * @param list<array<string, string>> $entries
     */
    private function writePackage(string $short, string $nsPrefix, array $entries, bool $extraSrc = true): void
    {
        $fs = new Filesystem();
        $dir = "{$this->tmpRoot}/packages/{$short}";
        $fs->mkdir("{$dir}/src");
        $fs->dumpFile("{$dir}/composer.json", json_encode([
            'name' => "waaseyaa/{$short}",
            'type' => 'library',
            'autoload' => ['psr-4' => [$nsPrefix => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if ($extraSrc) {
            foreach ($entries as $entry) {
                $fqcn = $entry['fqcn'];
                if (!str_starts_with($fqcn, $nsPrefix)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($fqcn, strlen($nsPrefix)));
                $ns = rtrim($nsPrefix, '\\') . (str_contains($relative, '/') ? '\\' . str_replace('/', '\\', dirname($relative)) : '');
                $short = basename($relative);
                $fs->dumpFile(
                    "{$dir}/src/{$relative}.php",
                    "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\ninterface {$short}\n{\n}\n",
                );
            }
        }

        $fs->dumpFile(
            "{$dir}/public-surface.php",
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(['entries' => $entries], true) . ";\n",
        );
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function generate(array $arguments): array
    {
        $process = new Process(
            array_merge([PHP_BINARY, $this->generator, '--root=' . $this->tmpRoot], $arguments),
            $this->repoRoot,
            null,
            null,
            120,
        );
        $exit = $process->run();

        return [$exit, $process->getOutput() . $process->getErrorOutput()];
    }

    /** @return array<string, string> */
    private function evaluatePhpMap(string $source): array
    {
        $tmp = tempnam($this->tmpRoot, 'map-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $source);
        /** @var mixed $map */
        $map = require $tmp;
        self::assertIsArray($map);

        return $map;
    }
}
