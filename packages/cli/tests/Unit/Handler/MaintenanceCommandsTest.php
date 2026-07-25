<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Handler\MaintenanceOffHandler;
use Waaseyaa\CLI\Handler\MaintenanceOnHandler;
use Waaseyaa\CLI\Handler\MaintenanceStatusHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;

#[CoversClass(MaintenanceOnHandler::class)]
#[CoversClass(MaintenanceOffHandler::class)]
#[CoversClass(MaintenanceStatusHandler::class)]
final class MaintenanceCommandsTest extends TestCase
{
    private string $flagPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa-maint-cli-' . bin2hex(random_bytes(6));
        mkdir($dir . '/storage', 0o755, true);
        $this->flagPath = $dir . '/storage/maintenance.flag';
    }

    protected function tearDown(): void
    {
        @unlink($this->flagPath);
        @rmdir(\dirname($this->flagPath));
        @rmdir(\dirname($this->flagPath, 2));
    }

    private function state(): MaintenanceState
    {
        return new MaintenanceState($this->flagPath);
    }

    private static function stubContainer(): \Psr\Container\ContainerInterface
    {
        return new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function onTester(): CliTester
    {
        $handler = new MaintenanceOnHandler($this->state());
        $definition = new HandlerCommand(
            name: 'maintenance:on',
            description: 'Enable maintenance mode.',
            options: [
                new HandlerOption(name: 'retry-after', mode: HandlerOptionMode::Required, description: ''),
                new HandlerOption(name: 'message', mode: HandlerOptionMode::Required, description: ''),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        return CliTester::for($definition, self::stubContainer());
    }

    private function offTester(): CliTester
    {
        $handler = new MaintenanceOffHandler($this->state());
        $definition = new HandlerCommand(
            name: 'maintenance:off',
            description: 'Disable maintenance mode.',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        return CliTester::for($definition, self::stubContainer());
    }

    private function statusTester(): CliTester
    {
        $handler = new MaintenanceStatusHandler($this->state());
        $definition = new HandlerCommand(
            name: 'maintenance:status',
            description: 'Report maintenance state.',
            options: [new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: '')],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        return CliTester::for($definition, self::stubContainer());
    }

    #[Test]
    public function on_enables_maintenance_with_custom_retry_after_and_message(): void
    {
        $tester = $this->onTester();
        $tester->executeMap(['--retry-after' => '300', '--message' => 'Swapping DB.']);

        self::assertSame(0, $tester->getExitCode());
        $status = $this->state()->read();
        self::assertTrue($status->active);
        self::assertSame(300, $status->retryAfter);
        self::assertSame('Swapping DB.', $status->message);
    }

    #[Test]
    public function on_is_idempotent(): void
    {
        $this->onTester()->executeMap([]);
        $second = $this->onTester();
        $second->executeMap([]);

        self::assertSame(0, $second->getExitCode(), 'Re-enabling maintenance is a success, not an error.');
        self::assertTrue($this->state()->read()->active);
        self::assertSame(120, $this->state()->read()->retryAfter, 'Default Retry-After applies when no option is given.');
    }

    #[Test]
    public function on_rejects_non_positive_retry_after(): void
    {
        $tester = $this->onTester();
        $tester->executeMap(['--retry-after' => 'abc']);

        self::assertSame(1, $tester->getExitCode());
        self::assertFalse($this->state()->read()->active, 'A bad argument must not leave a partial flag.');
    }

    #[Test]
    public function off_disables_and_is_idempotent(): void
    {
        $this->state()->activate(120, null);

        $first = $this->offTester();
        $first->executeMap([]);
        self::assertSame(0, $first->getExitCode());
        self::assertStringContainsString('DISABLED', $first->getStdout());
        self::assertFalse($this->state()->read()->active);

        // Idempotent: off again on an already-off app still succeeds.
        $second = $this->offTester();
        $second->executeMap([]);
        self::assertSame(0, $second->getExitCode());
        self::assertStringContainsString('already off', $second->getStdout());
    }

    #[Test]
    public function status_exit_code_is_zero_when_off_and_one_when_on(): void
    {
        $off = $this->statusTester();
        $off->executeMap([]);
        self::assertSame(0, $off->getExitCode());
        self::assertStringContainsString('OFF', $off->getStdout());

        $this->state()->activate(120, null);

        $on = $this->statusTester();
        $on->executeMap([]);
        self::assertSame(1, $on->getExitCode());
        self::assertStringContainsString('ON', $on->getStdout());
    }

    #[Test]
    public function status_json_reports_fail_closed_for_corrupt_flag(): void
    {
        file_put_contents($this->flagPath, 'garbage');

        $tester = $this->statusTester();
        $tester->executeMap(['--json' => true]);

        self::assertSame(1, $tester->getExitCode());
        $payload = json_decode($tester->getStdout(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['active']);
        self::assertTrue($payload['ambiguous']);
    }
}
