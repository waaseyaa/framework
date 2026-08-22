<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\FrankenPhp\WorkerAcceptance;

/**
 * Production-safe FrankenPHP worker-lane probe (#2494).
 *
 * Inert without the exact process-environment token. Request headers, wrong
 * SAPI, missing tests/, and arbitrary paths must not crash or activate.
 */
#[CoversClass(WorkerAcceptance::class)]
final class WorkerAcceptanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $envBackup;

    private string|false $tokenBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        $this->tokenBackup = getenv(WorkerAcceptance::PROCESS_ENV);
        putenv(WorkerAcceptance::PROCESS_ENV);
        unset($_ENV[WorkerAcceptance::PROCESS_ENV], $_SERVER[WorkerAcceptance::PROCESS_ENV]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        if ($this->tokenBackup === false) {
            putenv(WorkerAcceptance::PROCESS_ENV);
        } else {
            putenv(WorkerAcceptance::PROCESS_ENV . '=' . $this->tokenBackup);
        }
    }

    #[Test]
    public function process_token_ignores_forged_headers_and_reads_the_capability_env(): void
    {
        self::assertFalse(WorkerAcceptance::processToken(
            [],
            ['HTTP_X_WAASEYAA_FRANKENPHP_ACCEPTANCE' => WorkerAcceptance::TOKEN],
        ));
        putenv(WorkerAcceptance::PROCESS_ENV . '=' . WorkerAcceptance::TOKEN);
        self::assertSame(WorkerAcceptance::TOKEN, WorkerAcceptance::processToken([], []));
        putenv(WorkerAcceptance::PROCESS_ENV);
        self::assertFalse(WorkerAcceptance::processToken([], []));
    }

    #[Test]
    public function apply_is_inert_without_the_process_token(): void
    {
        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] = 'community-a';
        unset($_SESSION);
        WorkerAcceptance::apply(
            dirname(__DIR__, 4),
            false,
            WorkerAcceptance::SAPI,
            $_SERVER,
            '/tmp/waaseyaa-evil-probe.php',
        );
        self::assertArrayNotHasKey('waaseyaa_community_id', $_SESSION ?? []);
    }

    #[Test]
    public function apply_does_not_throw_when_the_tests_tree_is_absent(): void
    {
        $root = sys_get_temp_dir() . '/waaseyaa-missing-acceptance-' . uniqid('', true);
        self::assertTrue(mkdir($root, 0o755, true));
        try {
            WorkerAcceptance::apply($root, WorkerAcceptance::TOKEN, WorkerAcceptance::SAPI, [], false);
            self::assertFileDoesNotExist($root . '/tests/Acceptance/FrankenPhpWorker/probe.php');
        } finally {
            @rmdir($root);
        }
    }

    #[Test]
    public function a_non_frankenphp_sapi_is_inert(): void
    {
        $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] = 'community-a';
        unset($_SESSION);
        WorkerAcceptance::apply(
            dirname(__DIR__, 4),
            WorkerAcceptance::TOKEN,
            'cli',
            $_SERVER,
            false,
        );
        self::assertArrayNotHasKey('waaseyaa_community_id', $_SESSION ?? []);
    }

    #[Test]
    public function apply_can_run_twice_in_one_process(): void
    {
        $this->expectNotToPerformAssertions();
        $root = dirname(__DIR__, 4);
        WorkerAcceptance::apply($root, WorkerAcceptance::TOKEN, WorkerAcceptance::SAPI, [], false);
        WorkerAcceptance::apply($root, WorkerAcceptance::TOKEN, WorkerAcceptance::SAPI, [], false);
    }

    #[Test]
    public function an_environment_path_override_is_ignored(): void
    {
        $this->expectNotToPerformAssertions();
        $root = dirname(__DIR__, 4);
        $evil = sys_get_temp_dir() . '/waaseyaa-evil-probe-' . uniqid('', true) . '.php';
        file_put_contents($evil, '<?php throw new \\RuntimeException("evil probe executed");');
        try {
            WorkerAcceptance::apply($root, WorkerAcceptance::TOKEN, WorkerAcceptance::SAPI, [], $evil);
        } finally {
            @unlink($evil);
        }
    }
}
