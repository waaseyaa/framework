<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Maintenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Maintenance\MaintenanceState;
use Waaseyaa\Foundation\Maintenance\MaintenanceStatus;

#[CoversClass(MaintenanceState::class)]
#[CoversClass(MaintenanceStatus::class)]
final class MaintenanceStateTest extends TestCase
{
    private string $flagPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa-maint-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $this->flagPath = $dir . '/storage/maintenance.flag';
    }

    protected function tearDown(): void
    {
        $dir = \dirname($this->flagPath, 2);
        @unlink($this->flagPath);
        @rmdir(\dirname($this->flagPath));
        @rmdir($dir);
    }

    #[Test]
    public function absent_flag_reads_as_inactive(): void
    {
        $status = new MaintenanceState($this->flagPath)->read();

        self::assertFalse($status->active);
        self::assertFalse($status->ambiguous);
    }

    #[Test]
    public function activate_then_read_is_active_with_retry_after_and_message(): void
    {
        $state = new MaintenanceState($this->flagPath);
        $state->activate(300, 'Swapping the database.');

        $status = $state->read();

        self::assertTrue($status->active);
        self::assertFalse($status->ambiguous);
        self::assertSame(300, $status->retryAfter);
        self::assertSame('Swapping the database.', $status->message);
        self::assertIsString($status->since);
        // The flag directory is created on demand (mkdir -p).
        self::assertFileExists($this->flagPath);
    }

    #[Test]
    public function deactivate_removes_flag_and_is_idempotent(): void
    {
        $state = new MaintenanceState($this->flagPath);
        $state->activate(120, null);
        self::assertTrue($state->read()->active);

        $state->deactivate();
        self::assertFalse($state->read()->active);

        // Idempotent: a second off on an already-absent flag does not throw.
        $state->deactivate();
        self::assertFalse($state->read()->active);
    }

    #[Test]
    public function corrupt_flag_fails_closed_into_maintenance(): void
    {
        mkdir(\dirname($this->flagPath), 0o755, true);
        file_put_contents($this->flagPath, '{ this is not valid json');

        $status = new MaintenanceState($this->flagPath)->read();

        self::assertTrue($status->active, 'Corrupt flag must fail CLOSED into maintenance.');
        self::assertTrue($status->ambiguous);
        self::assertGreaterThan(0, $status->retryAfter, 'A safe default Retry-After is used when the flag is unreadable.');
    }

    #[Test]
    public function partial_flag_missing_active_key_fails_closed(): void
    {
        mkdir(\dirname($this->flagPath), 0o755, true);
        file_put_contents($this->flagPath, json_encode(['retry_after' => 90], JSON_THROW_ON_ERROR));

        $status = new MaintenanceState($this->flagPath)->read();

        self::assertTrue($status->active, 'A flag missing the "active" key is partial/corrupt and must fail CLOSED.');
        self::assertTrue($status->ambiguous);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongTypedActiveValues')]
    public function wrong_typed_active_value_fails_closed(string $rawJson): void
    {
        mkdir(\dirname($this->flagPath), 0o755, true);
        file_put_contents($this->flagPath, $rawJson);

        $status = new MaintenanceState($this->flagPath)->read();

        self::assertTrue(
            $status->active,
            sprintf('A present but wrong-typed "active" (%s) is ambiguous and must fail CLOSED.', $rawJson),
        );
        self::assertTrue($status->ambiguous);
    }

    /** @return iterable<string, array{string}> */
    public static function wrongTypedActiveValues(): iterable
    {
        yield 'integer 1' => ['{"active":1}'];
        yield 'string "true"' => ['{"active":"true"}'];
        yield 'null' => ['{"active":null}'];
        yield 'integer 0' => ['{"active":0}'];
    }

    #[Test]
    public function explicit_inactive_flag_reads_as_off(): void
    {
        mkdir(\dirname($this->flagPath), 0o755, true);
        file_put_contents($this->flagPath, json_encode(['active' => false], JSON_THROW_ON_ERROR));

        $status = new MaintenanceState($this->flagPath)->read();

        self::assertFalse($status->active);
        self::assertFalse($status->ambiguous);
    }
}
