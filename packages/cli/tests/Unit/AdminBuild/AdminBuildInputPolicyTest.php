<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\AdminBuild;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\AdminBuild\AdminBuildInputPolicy;
use Waaseyaa\CLI\AdminBuild\AdminBuildPolicyException;

#[CoversClass(AdminBuildInputPolicy::class)]
final class AdminBuildInputPolicyTest extends TestCase
{
    private string $projectRoot;
    private string $adminPath;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_admin_input_' . bin2hex(random_bytes(6));
        $this->adminPath = $this->projectRoot . '/packages/admin';
        mkdir($this->adminPath, 0700, true);
        file_put_contents($this->adminPath . '/package.json', json_encode([
            'name' => '@waaseyaa/synthetic-admin',
            'version' => '1.0.0',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->adminPath . '/package-lock.json', json_encode([
            'name' => '@waaseyaa/synthetic-admin',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => ['' => ['name' => '@waaseyaa/synthetic-admin', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function an_exact_matching_lock_and_safe_resolved_project_config_are_accepted(): void
    {
        file_put_contents($this->adminPath . '/.npmrc', "color=\${CI}\nengine-strict=true\n");

        $fingerprint = new AdminBuildInputPolicy()->validate($this->adminPath, ['CI' => 'true']);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $fingerprint);
    }

    #[Test]
    public function missing_or_mismatched_exact_lock_refuses(): void
    {
        unlink($this->adminPath . '/package-lock.json');
        $this->assertPolicyFailure('lock-missing');

        file_put_contents($this->adminPath . '/package-lock.json', json_encode([
            'name' => '@waaseyaa/other-admin',
            'version' => '1.0.0',
            'lockfileVersion' => 3,
            'packages' => ['' => ['name' => '@waaseyaa/other-admin', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
        $this->assertPolicyFailure('lock-mismatch');
    }

    #[Test]
    public function dotenv_input_refuses_without_reading_or_echoing_its_value(): void
    {
        $canary = 'cfg04-' . 'dotenv-' . 'credential-' . bin2hex(random_bytes(8));
        file_put_contents($this->adminPath . '/.env.production', $canary);

        $this->assertPolicyFailure('dotenv-present', forbiddenMessage: $canary);
    }

    #[Test]
    public function unresolved_npm_environment_expansion_refuses_before_launch(): void
    {
        file_put_contents($this->adminPath . '/.npmrc', 'color=${MISSING_BUILD_VALUE}');

        $this->assertPolicyFailure('npm-config-unresolved-environment', forbiddenMessage: 'MISSING_BUILD_VALUE');
    }

    #[Test]
    public function credential_bearing_or_execution_redirecting_npm_keys_refuse(): void
    {
        foreach (['_authToken', 'password', 'cafile', 'script-shell', 'node-options', 'registry'] as $key) {
            file_put_contents($this->adminPath . '/.npmrc', $key . '=synthetic');
            $this->assertPolicyFailure('npm-config-forbidden-key', forbiddenMessage: 'synthetic');
        }
    }

    private function assertPolicyFailure(string $expectedCode, string $forbiddenMessage = ''): void
    {
        try {
            new AdminBuildInputPolicy()->validate($this->adminPath, ['CI' => 'true']);
            self::fail('Expected Admin build input policy refusal.');
        } catch (AdminBuildPolicyException $e) {
            self::assertSame($expectedCode, $e->errorCode);
            if ($forbiddenMessage !== '') {
                self::assertStringNotContainsString($forbiddenMessage, $e->getMessage());
            }
        }
    }
}
