<?php

declare(strict_types=1);

namespace Waaseyaa\Tooling;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;

/**
 * Discovers contract shapes (interface/abstract class/trait/enum) under a
 * repository's `packages/*\/src` trees, and reports the declared shape of any
 * FQCN — the same php-parser AST walk previously inlined in
 * tools/check-surface-parity.php (docs/specs/public-surface-declarations.md §3),
 * lifted here unchanged in semantics and extended with `shape()`.
 *
 * `shape()` prefers reflection for loadable types (the real repository, where
 * every package/*\/src class is on vendor/autoload.php's classmap) and falls
 * back to the AST recorded during `scan()` for a `--root` fixture tree whose
 * classes are never autoloaded (tests/Architecture/SurfaceDeclarationCompositionTest.php).
 */
final class SurfaceScanner
{
    /** Contract shapes tracked as "public elements" — concrete classes are implementations, not contracts. */
    private const CONTRACT_SHAPES = ['interface', 'abstract class', 'trait', 'enum'];

    /** @var array<string, true> fqcn => true, contract shapes only */
    private array $contractShapes = [];

    /** @var array<string, string> fqcn => shape, every declared class-like symbol seen during the walk */
    private array $declaredShapes = [];

    private int $fileCount = 0;

    private function __construct(private readonly string $root)
    {
    }

    public static function scan(string $root): self
    {
        $scanner = new self($root);
        $scanner->run();

        return $scanner;
    }

    /** @return list<string> sorted FQCNs of interface/abstract class/trait/enum under packages/*\/src */
    public function contractShapes(): array
    {
        $keys = array_keys($this->contractShapes);
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function fileCount(): int
    {
        return $this->fileCount;
    }

    /**
     * The declared shape of $fqcn: one of interface, abstract class, trait,
     * enum, final readonly class, final class, readonly class, class — or
     * null if the type cannot be resolved at all (neither loadable nor seen
     * during the AST walk).
     */
    public function shape(string $fqcn): ?string
    {
        if (isset($this->declaredShapes[$fqcn])) {
            return $this->declaredShapes[$fqcn];
        }

        if (interface_exists($fqcn)) {
            return 'interface';
        }
        if (enum_exists($fqcn)) {
            return 'enum';
        }
        if (trait_exists($fqcn)) {
            return 'trait';
        }
        if (class_exists($fqcn)) {
            try {
                $reflection = new ReflectionClass($fqcn);
            } catch (\Throwable) {
                return null;
            }

            return self::classShape($reflection->isAbstract(), $reflection->isFinal(), $reflection->isReadOnly());
        }

        return null;
    }

    /**
     * The shape $fqcn is declared with in ONE file on disk, parsed
     * independently of the autoloader and of the scan() walk — null when the
     * file does not exist, does not parse, or does not declare that FQCN.
     *
     * This is how an on-disk definition outside packages/*\/src (a test helper
     * mapped only by a root autoload-dev PSR-4 entry — the #2926 StdinSource
     * case) is told apart from a declaration that nothing defines.
     */
    public function shapeInFile(string $path, string $fqcn): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        if (!class_exists(ParserFactory::class)) {
            throw new RuntimeException('nikic/php-parser is not installed.');
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse((string) file_get_contents($path));
        } catch (\Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        $visitor = self::shapeCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->shapes[$fqcn] ?? null;
    }

    public static function classShape(bool $abstract, bool $final, bool $readonly): string
    {
        if ($abstract) {
            return 'abstract class';
        }
        if ($final && $readonly) {
            return 'final readonly class';
        }
        if ($final) {
            return 'final class';
        }
        if ($readonly) {
            return 'readonly class';
        }

        return 'class';
    }

    /**
     * A fresh AST visitor recording every class-like declaration it sees as
     * fqcn => shape, shared by the scan() walk and shapeInFile().
     *
     * @return NodeVisitorAbstract&object{ns: string, shapes: array<string, string>}
     */
    private static function shapeCollector(): NodeVisitorAbstract
    {
        return new class extends NodeVisitorAbstract {
            public string $ns = '';
            /** @var array<string, string> fqcn => shape */
            public array $shapes = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->ns = $node->name !== null ? $node->name->toString() : '';

                    return null;
                }

                if (!isset($node->name)) {
                    return null;
                }

                $shape = match (true) {
                    $node instanceof Node\Stmt\Interface_ => 'interface',
                    $node instanceof Node\Stmt\Trait_ => 'trait',
                    $node instanceof Node\Stmt\Enum_ => 'enum',
                    $node instanceof Node\Stmt\Class_ => SurfaceScanner::classShape(
                        $node->isAbstract(),
                        $node->isFinal(),
                        $node->isReadonly(),
                    ),
                    default => null,
                };

                if ($shape === null) {
                    return null;
                }

                $fqcn = ($this->ns !== '' ? $this->ns . '\\' : '') . $node->name->toString();
                $this->shapes[$fqcn] = $shape;

                return null;
            }
        };
    }

    private function run(): void
    {
        $scanDirs = [];
        $packageDirectories = glob($this->root . '/packages/*', GLOB_ONLYDIR);
        foreach ($packageDirectories === false ? [] : $packageDirectories as $pkg) {
            if (is_dir("{$pkg}/src")) {
                $scanDirs[] = "{$pkg}/src";
            }
        }
        if (is_dir($this->root . '/src')) {
            $scanDirs[] = $this->root . '/src';
        }

        if (!class_exists(ParserFactory::class)) {
            throw new RuntimeException('nikic/php-parser is not installed.');
        }
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $collector = self::shapeCollector();

        foreach ($scanDirs as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $this->fileCount++;
                $source = (string) file_get_contents($file->getPathname());
                try {
                    $ast = $parser->parse($source);
                } catch (\Throwable $e) {
                    throw new RuntimeException("parse error in {$file->getPathname()}: {$e->getMessage()}", 0, $e);
                }
                if ($ast === null) {
                    continue;
                }
                $visitor = clone $collector;
                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                foreach ($visitor->shapes as $fqcn => $shape) {
                    $this->declaredShapes[$fqcn] = $shape;
                    if (in_array($shape, self::CONTRACT_SHAPES, true)) {
                        $this->contractShapes[$fqcn] = true;
                    }
                }
            }
        }
    }
}
