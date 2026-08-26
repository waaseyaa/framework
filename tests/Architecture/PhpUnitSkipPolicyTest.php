<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class PhpUnitSkipPolicyTest extends TestCase
{
    private string $root;
    private string $work;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->work = sys_get_temp_dir() . '/waaseyaa_skip_policy_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->work, 0o700, true));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->work);
    }

    #[Test]
    public function repository_inventory_is_complete_and_required_transports_are_non_skippable(): void
    {
        $result = $this->runChecker($this->root, $this->root . '/tools/phpunit-skip-policy.json');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            'phpunit-skip-policy: OK required_hosted=3 allowed=41 discovered=41',
            $result['output'],
        );
    }

    #[Test]
    public function an_unclassified_skip_fails_closed(): void
    {
        $root = $this->fixtureRoot(<<<'PHP'
            <?php
            final class ExampleTest {
                public function testIt(): void {
                    self::markTestSkipped('new capability');
                }
            }
            PHP);
        $policy = $this->writePolicy($root, []);

        $result = $this->runChecker($root, $policy);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('UNCLASSIFIED', $result['output']);
        self::assertStringContainsString('new capability', $result['output']);
    }

    #[Test]
    public function a_programming_failure_caught_as_throwable_cannot_be_allowlisted(): void
    {
        $root = $this->fixtureRoot(<<<'PHP'
            <?php
            final class ExampleTest {
                public function testIt(): void {
                    try {
                        throw new \TypeError('programming defect');
                    } catch (\Throwable $failure) {
                        self::markTestSkipped('optional peer: '.$failure->getMessage());
                    }
                }
            }
            PHP);
        $policy = $this->writePolicy($root, [[
            'path' => 'tests/ExampleTest.php',
            'reason' => 'optional peer: ',
            'occurrence' => 1,
            'classification' => 'declared-unavailability',
            'predicate' => 'Throwable',
            'rationale' => 'negative control',
        ]]);

        $result = $this->runChecker($root, $policy);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('BROAD_CATCH', $result['output']);
        self::assertStringContainsString('Throwable', $result['output']);
    }

    #[Test]
    public function a_narrow_declared_unavailability_exception_follows_the_allowed_disposition(): void
    {
        $root = $this->fixtureRoot(<<<'PHP'
            <?php
            final class ExampleTest {
                public function testIt(): void {
                    try {
                        $this->startOptionalPeer();
                    } catch (DeclaredPeerUnavailable $failure) {
                        self::markTestSkipped('optional peer: '.$failure->getMessage());
                    }
                }
            }
            PHP);
        $policy = $this->writePolicy($root, [[
            'path' => 'tests/ExampleTest.php',
            'reason' => 'optional peer: ',
            'occurrence' => 1,
            'classification' => 'declared-unavailability',
            'predicate' => 'DeclaredPeerUnavailable',
            'rationale' => 'the optional fixture has a narrow environmental exception',
        ]]);

        $result = $this->runChecker($root, $policy);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('allowed=1 discovered=1', $result['output']);
    }

    #[Test]
    public function an_unconditional_skip_cannot_be_made_optional_by_roster_prose_alone(): void
    {
        $root = $this->fixtureRoot(<<<'PHP'
            <?php
            final class ExampleTest {
                public function testIt(): void {
                    self::markTestSkipped('optional peer');
                }
            }
            PHP);
        $policy = $this->writePolicy($root, [[
            'path' => 'tests/ExampleTest.php',
            'reason' => 'optional peer',
            'occurrence' => 1,
            'classification' => 'platform-capability',
            'predicate' => 'invented predicate',
            'rationale' => 'negative control',
        ]]);

        $result = $this->runChecker($root, $policy);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('UNGUARDED_SKIP', $result['output']);
    }

    #[Test]
    public function a_required_hosted_file_cannot_contain_even_an_allowlisted_skip(): void
    {
        $root = $this->fixtureRoot(<<<'PHP'
            <?php
            final class RequiredTransportTest {
                public function testIt(): void {
                    if (!function_exists('proc_open')) {
                        self::markTestSkipped('proc_open unavailable');
                    }
                }
            }
            PHP);
        $policy = $this->writePolicy(
            $root,
            [[
                'path' => 'tests/ExampleTest.php',
                'reason' => 'proc_open unavailable',
                'occurrence' => 1,
                'classification' => 'platform-capability',
                'predicate' => 'function_exists(proc_open)',
                'rationale' => 'negative control',
            ]],
            [['path' => 'tests/ExampleTest.php', 'rationale' => 'required hosted proof']],
        );

        $result = $this->runChecker($root, $policy);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('REQUIRED_HOSTED_SKIP', $result['output']);
    }

    private function fixtureRoot(string $source): string
    {
        $root = $this->work . '/fixture-' . bin2hex(random_bytes(3));
        self::assertTrue(mkdir($root . '/tests', 0o700, true));
        file_put_contents($root . '/tests/ExampleTest.php', $source);

        return $root;
    }

    /**
     * @param list<array<string, int|string>> $allowed
     * @param list<array{path: string, rationale: string}> $required
     */
    private function writePolicy(string $root, array $allowed, array $required = []): string
    {
        $path = $root . '/policy.json';
        file_put_contents($path, json_encode([
            'schema_version' => 1,
            'required_hosted' => $required,
            'allowed_sites' => $allowed,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return $path;
    }

    /** @return array{exit: int, output: string} */
    private function runChecker(string $root, string $policy): array
    {
        $process = new Process([
            PHP_BINARY,
            $this->root . '/bin/check-phpunit-skip-policy',
            '--root=' . $root,
            '--policy=' . $policy,
        ], $this->root);
        $process->setTimeout(30.0);
        $exit = $process->run();

        return ['exit' => $exit, 'output' => $process->getOutput() . $process->getErrorOutput()];
    }
}
