<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Durable #2514 guard: Foundation unit tests that invoke AbstractKernel::boot
 * (or an entry point that does) must reset ProcessFieldReadRuntime in tearDown
 * so a later in-process test cannot inherit a leaked field-read registry.
 *
 * Construction without boot is out of scope. A ContentEntityBase reflection
 * poke is not cleanup — layoutFor reads EntityReadRuntime::$fieldRegistry.
 */
#[CoversNothing]
final class FoundationKernelFieldReadResetTest extends TestCase
{
    private const UNIT_ROOT = 'packages/foundation/tests/Unit';

    /** @var list<string> */
    private const KERNEL_BOOT_ENTRYPOINTS = [
        'publicBoot',
        'bootPublic',
        'bootForCli',
        'bootForFieldAccessPreflight',
        'bootForSchemaSync',
        'bootForMutationAuthorityBackfill',
        'publicRestrictedBoot',
        'runPreflightBoot',
    ];

    #[Test]
    public function scanner_detects_public_boot_without_teardown_reset(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $kernel = new class($root) extends AbstractKernel {
                        public function publicBoot(): void { $this->boot(); }
                    };
                    $kernel->publicBoot();
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertFalse($analysis['resets']);
    }

    #[Test]
    public function scanner_detects_http_kernel_handle_without_teardown_reset(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $response = new HttpKernel($root)->handle();
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertFalse($analysis['resets']);
    }

    #[Test]
    public function scanner_detects_reflected_boot_invoke_without_teardown_reset(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $kernel = new HttpKernel($root);
                    $boot = new \ReflectionMethod(AbstractKernel::class, 'boot');
                    $boot->invoke($kernel);
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertFalse($analysis['resets']);
    }

    #[Test]
    public function scanner_ignores_kernel_construction_without_boot(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $kernel = new HttpKernel($root);
                    $method = new \ReflectionMethod($kernel, 'applyTrustedProxiesFromConfig');
                    $method->invoke($kernel);
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertFalse($analysis['boots']);
    }

    #[Test]
    public function scanner_ignores_string_mentions_of_boot(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $this->assertMatchesRegularExpression('/\$this->boot\(\)/', $body);
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertFalse($analysis['boots']);
    }

    #[Test]
    public function scanner_ignores_service_provider_boot_calls(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $provider->boot();
                    $registry->boot($providers);
                    $bootstrapper->boot($projectRoot, []);
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertFalse($analysis['boots']);
    }

    #[Test]
    public function incomplete_content_entity_reflection_reset_does_not_count(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                protected function tearDown(): void {
                    $property = new \ReflectionProperty(ContentEntityBase::class, 'fieldRegistry');
                    $property->setValue(null, null);
                }
                public function test_it(): void {
                    $kernel = new class($root) extends AbstractKernel {
                        public function publicBoot(): void { $this->boot(); }
                    };
                    $kernel->publicBoot();
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertFalse($analysis['resets']);
    }

    #[Test]
    public function scanner_accepts_process_field_read_runtime_reset_in_teardown(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                protected function tearDown(): void {
                    ProcessFieldReadRuntime::reset();
                    putenv('APP_ENV');
                }
                public function test_it(): void {
                    $kernel = new class($root) extends AbstractKernel {
                        public function publicBoot(): void { $this->boot(); }
                    };
                    $kernel->publicBoot();
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertTrue($analysis['resets']);
    }

    #[Test]
    public function reset_only_inside_the_test_method_does_not_count(): void
    {
        $source = <<<'PHP'
            <?php
            final class ExampleTest extends TestCase {
                public function test_it(): void {
                    $kernel = new class($root) extends AbstractKernel {
                        public function publicBoot(): void { $this->boot(); }
                    };
                    $kernel->publicBoot();
                    ProcessFieldReadRuntime::reset();
                }
            }
            PHP;

        $analysis = $this->analyze($source);
        self::assertTrue($analysis['boots']);
        self::assertFalse($analysis['resets']);
    }

    #[Test]
    public function foundation_unit_tests_that_boot_kernels_reset_process_field_read_runtime(): void
    {
        $offenders = [];
        foreach ($this->phpFilesUnder(self::UNIT_ROOT) as $relative) {
            $source = (string) file_get_contents($this->repositoryRoot() . '/' . $relative);
            $analysis = $this->analyze($source);
            if ($analysis['boots'] && !$analysis['resets']) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Foundation unit tests that boot an AbstractKernel-derived kernel must call '
            . 'ProcessFieldReadRuntime::reset() from tearDown() so PHPUnit still clears the '
            . "process-wide field-read runtime when an assertion or boot throws.\n  - "
            . implode("\n  - ", $offenders),
        );
    }

    /**
     * @return array{boots: bool, resets: bool}
     */
    private function analyze(string $source): array
    {
        $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder();

        return [
            'boots' => $this->bootsKernel($finder, $ast),
            'resets' => $this->resetsInTearDown($finder, $ast),
        ];
    }

    /** @param list<Node> $ast */
    private function bootsKernel(NodeFinder $finder, array $ast): bool
    {
        foreach ($finder->findInstanceOf($ast, Stmt\Class_::class) as $class) {
            if (!$this->extendsKernel($class)) {
                continue;
            }
            $boot = $finder->findFirst($class->stmts, static function (Node $node): bool {
                return $node instanceof Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'boot'
                    && $node->var instanceof Expr\Variable
                    && $node->var->name === 'this'
                    && $node->args === [];
            });
            if ($boot !== null) {
                return true;
            }
        }

        foreach ($finder->findInstanceOf($ast, Expr\MethodCall::class) as $call) {
            if (!$call->name instanceof Node\Identifier) {
                continue;
            }
            $name = $call->name->toString();
            if (in_array($name, self::KERNEL_BOOT_ENTRYPOINTS, true)) {
                return true;
            }
            if ($name === 'handle' && $this->constructsHttpOrConsoleKernel($finder, $ast)) {
                return true;
            }
        }

        foreach ($finder->findInstanceOf($ast, Expr\New_::class) as $new) {
            if (!$new->class instanceof Name || $this->lastName($new->class) !== 'ReflectionMethod') {
                continue;
            }
            if (count($new->args) < 2) {
                continue;
            }
            $classArg = $new->args[0]->value;
            $methodArg = $new->args[1]->value;
            $isKernelClass = $classArg instanceof Expr\ClassConstFetch
                && $classArg->class instanceof Name
                && $this->isKernelClassName($classArg->class);
            $isBoot = $methodArg instanceof String_ && $methodArg->value === 'boot';
            if ($isKernelClass && $isBoot) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Node> $ast */
    private function resetsInTearDown(NodeFinder $finder, array $ast): bool
    {
        foreach ($finder->findInstanceOf($ast, Stmt\ClassMethod::class) as $method) {
            if ($method->name->toString() !== 'tearDown') {
                continue;
            }
            $reset = $finder->findFirst($method->stmts ?? [], function (Node $node): bool {
                return $node instanceof Expr\StaticCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'reset'
                    && $node->class instanceof Name
                    && $this->lastName($node->class) === 'ProcessFieldReadRuntime';
            });
            if ($reset !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Node> $ast */
    private function constructsHttpOrConsoleKernel(NodeFinder $finder, array $ast): bool
    {
        foreach ($finder->findInstanceOf($ast, Expr\New_::class) as $new) {
            if (!$new->class instanceof Name) {
                continue;
            }
            $last = $this->lastName($new->class);
            if ($last === 'HttpKernel' || $last === 'ConsoleKernel') {
                return true;
            }
        }

        return false;
    }

    private function extendsKernel(Stmt\Class_ $class): bool
    {
        return $class->extends instanceof Name && $this->isKernelClassName($class->extends);
    }

    private function isKernelClassName(Name $name): bool
    {
        return in_array($this->lastName($name), ['AbstractKernel', 'HttpKernel', 'ConsoleKernel'], true);
    }

    private function lastName(Name $name): string
    {
        $parts = $name->getParts();

        return $parts[array_key_last($parts)];
    }

    /** @return list<string> */
    private function phpFilesUnder(string $relativeDirectory): array
    {
        $root = $this->repositoryRoot();
        $directory = $root . '/' . $relativeDirectory;
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
        sort($files);

        return $files;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
