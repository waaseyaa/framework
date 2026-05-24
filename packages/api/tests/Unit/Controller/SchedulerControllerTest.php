<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

/**
 * Unit tests for the M4B WP02 admin scheduler dashboard controller.
 *
 * Mirrors `QueueControllerTest` (M4B WP01) in shape:
 *   - real `Schedule` registry seeded with a closure task,
 *   - real `ScheduleStateRepository` against in-memory SQLite,
 *   - real `ScheduleRunner` (no mocking — easier than juggling intersection
 *     types, and the runner has no I/O of its own beyond the repo + queue).
 *
 * The unit-vs-integration line: this test exercises controller behaviour
 * against real collaborators. `SchedulerAdminEndpointsTest` (integration)
 * additionally asserts the route registration + access-checker handshake.
 */
#[CoversClass(SchedulerController::class)]
final class SchedulerControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsRegisteredTasksWithNullLastRunWhenNeverRun(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'nightly-sync',
            expression: '0 2 * * *',
            command: fn() => null,
            description: 'Nightly content sync.',
        ));

        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $payload = $controller->index();

        self::assertCount(1, $payload['data']);
        $row = $payload['data'][0];
        self::assertSame('nightly-sync', $row['name']);
        self::assertSame('Nightly content sync.', $row['description']);
        self::assertSame('0 2 * * *', $row['expression']);
        self::assertNull($row['timezone']);
        self::assertNull($row['last_run_at']);
        self::assertNull($row['last_status']);
        self::assertNotEmpty($row['next_run_at']);
        // ATOM format includes a TZ offset
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $row['next_run_at']);
    }

    #[Test]
    public function indexSurfacesLastRunStateAfterRunOne(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'hourly-job',
            expression: '0 * * * *',
            command: fn() => null,
        ));

        $stateRepo = self::makeStateRepository();
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock(), $stateRepo);
        $runner->runOne('hourly-job', new \DateTimeImmutable());

        $controller = new SchedulerController($schedule, $stateRepo, $runner);
        $payload = $controller->index();

        self::assertSame('success', $payload['data'][0]['last_status']);
        self::assertNotNull($payload['data'][0]['last_run_at']);
    }

    #[Test]
    public function indexReturnsEmptyArrayWhenNoTasksRegistered(): void
    {
        $schedule = new Schedule();
        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $payload = $controller->index();

        self::assertSame([], $payload['data']);
    }

    #[Test]
    public function triggerReturns200WithSuccessEnvelopeOnHappyPath(): void
    {
        $invoked = false;
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'manual-now',
            expression: '0 0 1 1 *', // Never due — runOne() bypasses isDue().
            command: function () use (&$invoked) {
                $invoked = true;
            },
        ));

        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $response = $controller->trigger('manual-now');

        self::assertTrue($invoked);
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('success', $body['status']);
        self::assertArrayHasKey('message', $body);
        self::assertArrayNotHasKey('exception_class', $body);
    }

    #[Test]
    public function triggerReturns404WhenTaskIsUnknown(): void
    {
        $schedule = new Schedule();
        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $response = $controller->trigger('ghost');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('1.1', $body['jsonapi']['version']);
        self::assertSame('Not Found', $body['errors'][0]['title']);
        self::assertStringContainsString('ghost', $body['errors'][0]['detail']);
    }

    #[Test]
    public function triggerExtractsThrowableIntoStructuredEnvelopeWithoutSerializingException(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'kaboom',
            expression: '* * * * *',
            command: fn() => throw new \DomainException('this is fine'),
        ));

        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $response = $controller->trigger('kaboom');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('failed', $body['status']);
        self::assertSame('this is fine', $body['message']);
        self::assertSame(\DomainException::class, $body['exception_class']);

        // FR-010 guard — the body must not have round-tripped a serialized
        // Throwable (line/file/trace from PHP's default serializer).
        $raw = (string) $response->getContent();
        self::assertStringNotContainsString('"trace"', $raw, 'response must not include a stack trace');
        self::assertStringNotContainsString('"file"', $raw, 'response must not include a file path');
        self::assertStringNotContainsString('"line"', $raw, 'response must not include a line number');
    }

    #[Test]
    public function triggerExecutesStringCommandTasksByDispatchingToQueue(): void
    {
        $schedule = new Schedule();
        $schedule->add(new ScheduledTask(
            name: 'enqueue-me',
            expression: '0 0 1 1 *',
            command: \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::class,
        ));
        \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::reset();

        $controller = new SchedulerController(
            $schedule,
            self::makeStateRepository(),
            self::makeRunner($schedule),
        );

        $response = $controller->trigger('enqueue-me');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('success', $body['status']);
    }

    private static function makeStateRepository(): ScheduleStateRepository
    {
        $db = DBALDatabase::createSqlite();
        $db->query('
            CREATE TABLE waaseyaa_schedule_state (
                task_name VARCHAR(255) PRIMARY KEY,
                last_run_at VARCHAR(50) NOT NULL,
                last_result TEXT NOT NULL
            )
        ');

        return new ScheduleStateRepository($db);
    }

    private static function makeRunner(Schedule $schedule): ScheduleRunner
    {
        return new ScheduleRunner(
            $schedule,
            new SyncQueue(),
            new InMemoryLock(),
            self::makeStateRepository(),
        );
    }
}
