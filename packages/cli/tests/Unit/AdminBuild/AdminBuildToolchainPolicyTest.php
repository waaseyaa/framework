<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildEnvironment;
use Waaseyaa\CLI\AdminBuild\AdminBuildPolicyException;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessRunnerInterface;
use Waaseyaa\CLI\AdminBuild\AdminBuildProcessResult;
use Waaseyaa\CLI\AdminBuild\AdminBuildToolchainPolicy;
use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;

#[CoversClass(AdminBuildToolchainPolicy::class)]
final class AdminBuildToolchainPolicyTest extends TestCase
{
    #[Test]
    public function exact_pinned_node_major_is_required_before_npm_runs(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa_node_pin_' . bin2hex(random_bytes(5));
        mkdir($root, 0700, true);
        file_put_contents($root . '/.nvmrc', "24\n");
        $environment = new AdminBuildEnvironment('/synthetic/npm', '/synthetic/node', ['CI' => 'true']);

        try {
            $version = new AdminBuildToolchainPolicy()->validate(
                $root,
                $environment,
                new FixedVersionRunner('v24.19.0'),
                new RedactorProcessor(),
            );
            self::assertSame('v24.19.0', $version);

            $this->expectException(AdminBuildPolicyException::class);
            $this->expectExceptionMessage('node-version-mismatch');
            new AdminBuildToolchainPolicy()->validate(
                $root,
                $environment,
                new FixedVersionRunner('v25.9.0'),
                new RedactorProcessor(),
            );
        } finally {
            unlink($root . '/.nvmrc');
            rmdir($root);
        }
    }
}

final class FixedVersionRunner implements AdminBuildProcessRunnerInterface
{
    public function __construct(private readonly string $version) {}

    public function run(
        array $command,
        string $cwd,
        array $environment,
        RedactorProcessor $sanitizer,
        callable $stdout,
        callable $stderr,
    ): AdminBuildProcessResult {
        $stdout($this->version . "\n");

        return new AdminBuildProcessResult(0);
    }
}
