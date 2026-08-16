<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessException;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessRunner;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

#[CoversClass(AdminBuildProcessRunner::class)]
final class AdminBuildProcessRunnerTest extends TestCase
{
    #[Test]
    public function split_stdout_and_stderr_canaries_are_sanitized_before_callbacks(): void
    {
        $canary = 'cfg04-' . 'stream-' . 'credential-' . bin2hex(random_bytes(10));
        $sanitizer = new RedactorProcessor(registeredValues: [$canary]);
        $stdout = '';
        $stderr = '';
        $code = <<<'PHP'
$value = $argv[1];
fwrite(STDOUT, substr($value, 0, 7));
fflush(STDOUT);
fwrite(STDOUT, substr($value, 7) . "\n");
fwrite(STDERR, substr($value, 0, 5));
fflush(STDERR);
fwrite(STDERR, substr($value, 5) . "\n");
PHP;

        $result = new AdminBuildProcessRunner()->run(
            command: [PHP_BINARY, '-r', $code, $canary],
            cwd: sys_get_temp_dir(),
            environment: ['PATH' => dirname(PHP_BINARY)],
            sanitizer: $sanitizer,
            stdout: static function (string $chunk) use (&$stdout): void { $stdout .= $chunk; },
            stderr: static function (string $chunk) use (&$stderr): void { $stderr .= $chunk; },
        );

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString($canary, $stdout . $stderr);
        self::assertStringContainsString(RedactorProcessor::SENTINEL, $stdout);
        self::assertStringContainsString(RedactorProcessor::SENTINEL, $stderr);
    }

    #[Test]
    public function a_registered_multiline_canary_is_sanitized_before_callbacks(): void
    {
        $canary = "cfg04-multiline-canary-AAAAAAAA\nsecond-line-BBBBBBBB";
        $captured = '';

        new AdminBuildProcessRunner()->run(
            command: [PHP_BINARY, '-r', 'fwrite(STDOUT, "prefix\\n" . $argv[1] . "\\nsuffix\\n");', $canary],
            cwd: sys_get_temp_dir(),
            environment: ['PATH' => dirname(PHP_BINARY)],
            sanitizer: new RedactorProcessor(registeredValues: [$canary]),
            stdout: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
            stderr: static function (string $chunk): void {},
        );

        self::assertStringNotContainsString($canary, $captured);
        self::assertStringContainsString(RedactorProcessor::SENTINEL, $captured);
    }

    #[Test]
    public function excessive_child_output_is_dropped_with_a_fixed_non_sensitive_code(): void
    {
        $canary = 'cfg04-' . 'overflow-' . 'credential-' . bin2hex(random_bytes(8));
        $captured = '';

        try {
            new AdminBuildProcessRunner(maxOutputBytesPerStream: 64)->run(
                command: [PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat($argv[1], 20));', $canary],
                cwd: sys_get_temp_dir(),
                environment: ['PATH' => dirname(PHP_BINARY)],
                sanitizer: new RedactorProcessor(registeredValues: [$canary]),
                stdout: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
                stderr: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
            );
            self::fail('Output overflow must refuse.');
        } catch (AdminBuildProcessException $e) {
            self::assertSame('child-output-limit', $e->errorCode);
            self::assertStringNotContainsString($canary, $captured . $e->getMessage());
        }
    }

    #[Test]
    public function a_canary_split_between_stdout_and_stderr_drops_both_raw_streams(): void
    {
        $canary = 'cfg04-' . 'cross-stream-' . bin2hex(random_bytes(10));
        $captured = '';
        $code = <<<'PHP'
$value = $argv[1];
$split = intdiv(strlen($value), 2);
fwrite(STDOUT, substr($value, 0, $split));
fwrite(STDERR, substr($value, $split));
PHP;

        new AdminBuildProcessRunner()->run(
            command: [PHP_BINARY, '-r', $code, $canary],
            cwd: sys_get_temp_dir(),
            environment: ['PATH' => dirname(PHP_BINARY)],
            sanitizer: new RedactorProcessor(registeredValues: [$canary]),
            stdout: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
            stderr: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
        );

        self::assertSame(RedactorProcessor::SENTINEL, $captured);
        self::assertStringNotContainsString($canary, $captured);
    }

    #[Test]
    public function a_canary_split_stderr_first_then_stdout_drops_both_raw_streams(): void
    {
        $canary = 'cfg04-' . 'reverse-stream-' . bin2hex(random_bytes(10));
        $captured = '';
        $code = <<<'PHP'
$value = $argv[1];
$split = intdiv(strlen($value), 2);
fwrite(STDERR, "cookie warning\n" . substr($value, 0, $split));
fwrite(STDOUT, substr($value, $split));
PHP;

        new AdminBuildProcessRunner()->run(
            command: [PHP_BINARY, '-r', $code, $canary],
            cwd: sys_get_temp_dir(),
            environment: ['PATH' => dirname(PHP_BINARY)],
            sanitizer: new RedactorProcessor(registeredValues: [$canary]),
            stdout: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
            stderr: static function (string $chunk) use (&$captured): void { $captured .= $chunk; },
        );

        self::assertSame(RedactorProcessor::SENTINEL, $captured);
        self::assertStringNotContainsString($canary, $captured);
    }

    #[Test]
    public function a_stalled_child_is_terminated_at_the_fixed_runtime_bound(): void
    {
        $started = microtime(true);

        try {
            new AdminBuildProcessRunner(maxRuntimeSeconds: 0.1)->run(
                command: [PHP_BINARY, '-r', 'while (true) {}'],
                cwd: sys_get_temp_dir(),
                environment: ['PATH' => dirname(PHP_BINARY)],
                sanitizer: new RedactorProcessor(),
                stdout: static function (string $chunk): void {},
                stderr: static function (string $chunk): void {},
            );
            self::fail('A stalled child must be terminated.');
        } catch (AdminBuildProcessException $e) {
            self::assertSame('child-runtime-limit', $e->errorCode);
            self::assertLessThan(3.0, microtime(true) - $started);
        }
    }

    #[Test]
    public function npm_cache_miss_is_classified_before_sensitive_diagnostic_lines_are_sanitized(): void
    {
        $stderr = '';
        $code = 'fwrite(STDERR, "npm error code ENOTCACHED\\nnpm error request for cookie-es failed\\n"); exit(1);';

        $result = new AdminBuildProcessRunner()->run(
            command: [PHP_BINARY, '-r', $code],
            cwd: sys_get_temp_dir(),
            environment: ['PATH' => dirname(PHP_BINARY)],
            sanitizer: new RedactorProcessor(),
            stdout: static function (string $chunk): void {},
            stderr: static function (string $chunk) use (&$stderr): void { $stderr .= $chunk; },
        );

        self::assertSame(1, $result->exitCode);
        self::assertSame('ENOTCACHED', $result->npmErrorCode);
        self::assertStringContainsString('npm error code ENOTCACHED', $stderr);
        self::assertStringContainsString(RedactorProcessor::SENTINEL, $stderr);
    }
}
