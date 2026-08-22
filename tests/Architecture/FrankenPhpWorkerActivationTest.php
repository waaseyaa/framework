<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed activation for the FrankenPHP worker-lane probe (#2494).
 */
#[CoversNothing]
final class FrankenPhpWorkerActivationTest extends TestCase
{
    private string $repoRoot;

    private string $activate;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->activate = $this->repoRoot . '/tests/Acceptance/FrankenPhpWorker/activate-resolve.php';
        require_once $this->activate;
    }

    #[Test]
    public function the_front_controller_does_not_require_an_environment_path(): void
    {
        $front = (string) file_get_contents($this->repoRoot . '/public/index.php');
        self::assertStringContainsString('tests/Acceptance/FrankenPhpWorker/activate.php', $front);
        self::assertStringContainsString('worker-lane-v1', $front);
        self::assertStringNotContainsString('WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE', $front);
        self::assertDoesNotMatchRegularExpression(
            '/require\s+\$acceptanceProbe/',
            $front,
        );
        self::assertStringNotContainsString('HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE', $front);
    }

    #[Test]
    public function a_path_override_cannot_replace_the_repository_probe(): void
    {
        $probe = waaseyaa_frankenphp_acceptance_resolve(
            $this->repoRoot,
            'worker-lane-v1',
            'frankenphp',
            ['HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE' => 'worker-lane-v1'],
            '/tmp/waaseyaa-evil-probe.php',
        );
        self::assertSame(
            $this->repoRoot . '/tests/Acceptance/FrankenPhpWorker/probe.php',
            $probe,
        );
    }

    #[Test]
    public function a_missing_probe_fixture_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe fixture is missing');
        waaseyaa_frankenphp_acceptance_resolve(
            sys_get_temp_dir() . '/waaseyaa-missing-acceptance-' . uniqid(),
            'worker-lane-v1',
            'frankenphp',
            [],
            false,
        );
    }

    #[Test]
    public function request_headers_cannot_satisfy_the_token(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exact worker-lane token');
        waaseyaa_frankenphp_acceptance_resolve(
            $this->repoRoot,
            false,
            'frankenphp',
            ['HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE' => 'worker-lane-v1'],
            false,
        );
    }

    #[Test]
    public function a_non_frankenphp_sapi_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires SAPI frankenphp');
        waaseyaa_frankenphp_acceptance_resolve(
            $this->repoRoot,
            'worker-lane-v1',
            'cli',
            [],
            false,
        );
    }

    #[Test]
    public function config_maps_auth_token_secret_independently(): void
    {
        $config = (string) file_get_contents($this->repoRoot . '/config/waaseyaa.php');
        self::assertStringContainsString("getenv('AUTH_TOKEN_SECRET')", $config);
        self::assertDoesNotMatchRegularExpression(
            "/token_secret['\"]\s*=>\s*getenv\('AUTH_TOKEN_SECRET'\)\s*\?:\s*\(getenv\('WAASEYAA_APP_SECRET'\)/",
            $config,
        );
        self::assertStringContainsString("getenv('WAASEYAA_STORAGE_PATH')", $config);
    }
}
