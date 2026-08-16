<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Audit\ConfigAuditChannel;
use Waaseyaa\Config\Audit\ConfigAuditEvent;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\Config\Exception\ConfigImportFailedException;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportEntryResult;
use Waaseyaa\Config\Sync\ConfigResetter;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Config\Sync\ConfigSyncRepository;

#[CoversClass(ConfigResetter::class)]
final class ConfigResetterTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_config_resetter_' . uniqid('', true);
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function reset_calls_apply_hook_once_and_returns_hook_status(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $hook = new class implements ConfigImportApplyHookInterface {
            /** @var list<string> */
            public array $applied = [];

            public function apply(ConfigSyncFile $file): string
            {
                $this->applied[] = $file->ref();

                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void {}
        };
        $resetter = new ConfigResetter($repository, $hook);

        $result = $resetter->reset('role.admin');

        self::assertSame(['role.admin'], $hook->applied);
        self::assertSame(ConfigImportEntryResult::STATUS_UPDATED, $result->status);
        self::assertSame('role.admin', $result->ref);
    }

    #[Test]
    public function missing_sync_file_returns_failed_without_calling_hook(): void
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        $hook = new class implements ConfigImportApplyHookInterface {
            public bool $called = false;

            public function apply(ConfigSyncFile $file): string
            {
                $this->called = true;

                return ConfigImportEntryResult::STATUS_UPDATED;
            }

            public function delete(string $ref): void {}
        };
        $resetter = new ConfigResetter($repository, $hook);

        $result = $resetter->reset('role.ghost');

        self::assertTrue($result->isFailure());
        self::assertFalse($hook->called, 'apply hook must not be called for missing sync entity');
        self::assertNotNull($result->reason);
        self::assertStringContainsString('role.ghost', $result->reason);
    }

    #[Test]
    public function successful_reset_emits_info_audit_event_with_op_reset(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $hook = $this->stubHook(ConfigImportEntryResult::STATUS_UPDATED);

        /** @var list<array{level: string, message: string, event: ConfigAuditEvent}> $log */
        $log = [];
        $logger = static function (string $level, string $message, ConfigAuditEvent $event) use (&$log): void {
            $log[] = ['level' => $level, 'message' => $message, 'event' => $event];
        };

        $resetter = new ConfigResetter($repository, $hook, $logger);
        $resetter->reset('role.admin', actor: 'alice', skipConfirmation: true);

        self::assertCount(1, $log);
        self::assertSame('info', $log[0]['level']);
        self::assertStringContainsString('role.admin', $log[0]['message']);
        self::assertSame(ConfigAuditEvent::OP_RESET, $log[0]['event']->operation);
        self::assertSame('alice', $log[0]['event']->actor);
        self::assertSame('role.admin', $log[0]['event']->entityRef);
        self::assertSame(ConfigAuditChannel::CHANNEL, $log[0]['event']->context['channel']);
        self::assertTrue($log[0]['event']->context['skip_confirmation']);
        self::assertSame(ConfigImportEntryResult::STATUS_UPDATED, $log[0]['event']->context['hook_status']);
    }

    #[Test]
    public function apply_failure_surfaces_status_failed_and_logs_warning_event(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw ConfigImportFailedException::applyFailed($file->ref(), 'db lock timeout');
            }

            public function delete(string $ref): void {}
        };
        /** @var list<array{level: string, event: ConfigAuditEvent}> $log */
        $log = [];
        $logger = static function (string $level, string $_message, ConfigAuditEvent $event) use (&$log): void {
            $log[] = ['level' => $level, 'event' => $event];
        };

        $resetter = new ConfigResetter($repository, $hook, $logger);
        $result = $resetter->reset('role.admin', actor: 'bob');

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->reason);
        self::assertStringContainsString('db lock timeout', $result->reason);
        self::assertCount(1, $log);
        self::assertSame('warning', $log[0]['level']);
        self::assertSame(ConfigAuditEvent::OP_RESET, $log[0]['event']->operation);
        self::assertArrayHasKey('reason', $log[0]['event']->context);
    }

    #[Test]
    public function untyped_throwable_is_wrapped_and_status_failed_recorded(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw new \RuntimeException('disk full');
            }

            public function delete(string $ref): void {}
        };
        $resetter = new ConfigResetter($repository, $hook);

        $result = $resetter->reset('role.admin');

        self::assertTrue($result->isFailure());
        self::assertNotNull($result->reason);
        self::assertStringContainsString('disk full', $result->reason);
    }

    #[Test]
    public function audit_event_records_skip_confirmation_default_false(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $hook = $this->stubHook(ConfigImportEntryResult::STATUS_UPDATED);
        /** @var list<ConfigAuditEvent> $events */
        $events = [];
        $logger = static function (string $_level, string $_message, ConfigAuditEvent $event) use (&$events): void {
            $events[] = $event;
        };

        $resetter = new ConfigResetter($repository, $hook, $logger);
        $resetter->reset('role.admin');

        self::assertCount(1, $events);
        self::assertFalse($events[0]->context['skip_confirmation']);
        self::assertNull($events[0]->actor);
    }

    #[Test]
    public function productionResetRefusesUntilACompleteVerifiedBundlePlannerExists(): void
    {
        $repository = $this->seed(['role.admin' => []]);
        $activeFile = ConfigSyncFile::writable(
            'role',
            'admin',
            ConfigSyncFile::deterministicUuid('role', 'admin'),
            [],
            'en',
            ['label' => 'Old'],
            schemaId: 'waaseyaa.test.config',
            schemaVersion: 1,
            schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ownerPackage: 'waaseyaa/config',
            ownerConfigContractVersion: 1,
        );
        $source = new class ($activeFile) implements ConfigSyncFileSourceInterface {
            public function __construct(private readonly ConfigSyncFile $file) {}
            public function iterate(): iterable
            {
                yield $this->file;
            }
        };
        $hook = new class implements ConfigImportApplyHookInterface {
            public function apply(ConfigSyncFile $file): string
            {
                throw new \LogicException('legacy hook called');
            }
            public function delete(string $ref): void
            {
                throw new \LogicException('legacy hook called');
            }
        };
        $expected = new ConfigurationActiveToken(str_repeat('a', 64), 4);
        $activator = $this->createMock(ConfigurationActivatorInterface::class);
        $activator->expects($this->never())->method('activate');
        $resetter = new ConfigResetter(
            $repository,
            $hook,
            activator: $activator,
            activeSource: $source,
        );

        $result = $resetter->reset(
            'role.admin',
            activationRequestId: 'reset-request-1',
            expectedToken: $expected,
        );

        self::assertSame(ConfigImportEntryResult::STATUS_FAILED, $result->status);
        self::assertStringContainsString('complete verified CFG-03 bundle planner', (string) $result->reason);
    }

    /**
     * @param array<string, list<string>> $refsWithDeps
     */
    private function seed(array $refsWithDeps): ConfigSyncRepository
    {
        $repository = new ConfigSyncRepository($this->tempDir);
        foreach ($refsWithDeps as $ref => $dependencies) {
            [$entityType, $entityId] = explode('.', $ref, 2);
            $file = ConfigSyncFile::writable(
                entityType: $entityType,
                entityId: $entityId,
                uuid: ConfigSyncFile::deterministicUuid($entityType, $entityId),
                dependencies: $dependencies,
                langcode: 'en',
                fields: [],
                schemaId: 'waaseyaa.test.config',
                schemaVersion: 1,
                schemaHash: 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ownerPackage: 'waaseyaa/config',
                ownerConfigContractVersion: 1,
            );
            $repository->put($file);
        }

        return $repository;
    }

    private function stubHook(string $status): ConfigImportApplyHookInterface
    {
        return new class ($status) implements ConfigImportApplyHookInterface {
            public function __construct(private readonly string $status) {}

            public function apply(ConfigSyncFile $file): string
            {
                return $this->status;
            }

            public function delete(string $ref): void {}
        };
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
