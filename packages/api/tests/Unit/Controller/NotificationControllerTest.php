<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Notification\NotificationInterface;
use Waaseyaa\Queue\SyncQueue;

/**
 * Unit tests for the M4C WP01 notification admin controller.
 *
 * Mirrors the M4B QueueController / SchedulerController test shape:
 *   - construct anonymous fakes for the collaborators
 *   - cover happy path + 404 + 500 (Throwable extracted, never serialized)
 *
 * Spec: kitty-specs/notification-rules-admin-01KSDRNW/spec.md
 */
#[CoversClass(NotificationController::class)]
final class NotificationControllerTest extends TestCase
{
    #[Test]
    public function indexReturnsRegisteredChannelsAsTypeClassPairs(): void
    {
        $mail = self::recordingChannel();
        $database = self::recordingChannel();
        $dispatcher = new NotificationDispatcher(
            new SyncQueue(),
            ['mail' => $mail, 'database' => $database],
        );
        $controller = new NotificationController($dispatcher);

        $payload = $controller->index(new Request());

        self::assertArrayHasKey('data', $payload);
        self::assertCount(2, $payload['data']);
        self::assertSame('mail', $payload['data'][0]['type']);
        self::assertSame($mail::class, $payload['data'][0]['class']);
        self::assertSame('database', $payload['data'][1]['type']);
        self::assertSame($database::class, $payload['data'][1]['class']);
    }

    #[Test]
    public function indexReturnsEmptyDataWhenNoChannelsRegistered(): void
    {
        $dispatcher = new NotificationDispatcher(new SyncQueue(), []);
        $controller = new NotificationController($dispatcher);

        $payload = $controller->index(new Request());

        self::assertSame(['data' => []], $payload);
    }

    #[Test]
    public function testInvokesSendOnTheNamedChannelAndReturnsSuccessEnvelope(): void
    {
        $channel = self::recordingChannel();
        $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $channel]);
        $controller = new NotificationController($dispatcher);

        $response = $controller->test(new Request(), 'mail');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('mail', $body['type']);
        self::assertSame('success', $body['status']);
        self::assertSame('Test sent.', $body['message']);
        self::assertArrayNotHasKey('exception_class', $body);
        self::assertSame(1, $channel->sentCount(), 'Channel send() must be invoked exactly once.');
    }

    #[Test]
    public function testReturns404WithJsonApiErrorEnvelopeWhenChannelUnknown(): void
    {
        $dispatcher = new NotificationDispatcher(
            new SyncQueue(),
            ['mail' => self::recordingChannel()],
        );
        $controller = new NotificationController($dispatcher);

        $response = $controller->test(new Request(), 'does-not-exist');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('1.1', $body['jsonapi']['version']);
        self::assertSame('Not Found', $body['errors'][0]['title']);
        self::assertStringContainsString('does-not-exist', $body['errors'][0]['detail']);
    }

    #[Test]
    public function testReturns500WithStructuredEnvelopeAndExceptionClassWhenChannelThrows(): void
    {
        $channel = new class implements ChannelInterface {
            public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
            {
                throw new \DomainException('SMTP transport refused');
            }
        };
        $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $channel]);
        $controller = new NotificationController($dispatcher);

        $response = $controller->test(new Request(), 'mail');

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('mail', $body['type']);
        self::assertSame('failed', $body['status']);
        self::assertSame('SMTP transport refused', $body['message']);
        self::assertSame(\DomainException::class, $body['exception_class']);
    }

    #[Test]
    public function testThrowableNeverEscapesAsRawObjectInJson(): void
    {
        // Regression guard: ensure the JSON body has only scalar values for
        // all top-level keys (no nested exception trace, no class instance).
        $channel = new class implements ChannelInterface {
            public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
            {
                throw new \RuntimeException('boom', code: 42);
            }
        };
        $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $channel]);
        $controller = new NotificationController($dispatcher);

        $response = $controller->test(new Request(), 'mail');

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($body as $value) {
            self::assertTrue(
                is_string($value) || is_int($value) || is_bool($value) || $value === null,
                'JSON envelope must not contain non-scalar values (Throwable leak guard).',
            );
        }
    }

    /**
     * Build a `ChannelInterface` that records how many times `send()` ran.
     * Anonymous class is preferred over `createMock()` per testing gotcha
     * (createMock() can't mock `final class`; here we just need an inline fake).
     */
    private static function recordingChannel(): ChannelInterface
    {
        return new class implements ChannelInterface {
            private int $sent = 0;

            public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
            {
                $this->sent++;
            }

            public function sentCount(): int
            {
                return $this->sent;
            }
        };
    }
}
