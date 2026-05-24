<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Notification\NotificationInterface;

/**
 * Admin-only HTTP controller for the notification channels dashboard (M4C WP01).
 *
 * Exposes two actions backing `/notifications`:
 *   - `index` — list every registered channel `{type, class}` pair so the
 *               operator can see what notification surfaces are configured.
 *   - `test`  — fire a synthetic notification through one channel by type, to
 *               confirm "is mail actually working in this environment" without
 *               writing a one-off test script. Anonymous `TestRecipient` +
 *               `TestNotification` are constructed inline; the call to
 *               `ChannelInterface::send()` is wrapped in try-catch so the JSON
 *               boundary never receives a `\Throwable` (FR-010).
 *
 * Delivery log and channel enable/disable are deliberately out of scope —
 * `waaseyaa/notification` does not yet ship persistence for those. Follow-up
 * tracked under the M4C audit closure (C-L3-02 + C-L0-03).
 *
 * Access control: enforced by the route option `_role: admin` (see
 * `BuiltinRouteRegistrar`). NFR-001 — do NOT re-check the role here.
 *
 * Mirrors `QueueController` / `SchedulerController` (M4B) for shape, error
 * envelope, and exception extraction.
 *
 * @api
 */
final class NotificationController
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * `GET /api/notification/channels` — list registered notification channels.
     *
     * Channel order matches the constructor-provided map (typically the order
     * in which `NotificationServiceProvider::buildChannels()` added them).
     *
     * @return array{
     *   data: list<array{type: string, class: string}>
     * }
     */
    public function index(Request $request): array
    {
        $rows = [];
        foreach ($this->dispatcher->channels() as $type => $channel) {
            $rows[] = [
                'type' => $type,
                'class' => $channel::class,
            ];
        }

        return ['data' => $rows];
    }

    /**
     * `POST /api/notification/channels/{type}/test` — synthetic test send.
     *
     * Returns:
     *   - 200 `{type, status: "success", message}` when the channel accepts
     *     the synthetic notification without throwing.
     *   - 404 JSON:API error envelope when `{type}` is not a registered
     *     channel.
     *   - 500 `{type, status: "failed", message, exception_class}` when the
     *     channel throws — the throwable never crosses the JSON boundary
     *     directly (FR-010).
     */
    public function test(Request $request, string $type): Response
    {
        $channels = $this->dispatcher->channels();
        $channel = $channels[$type] ?? null;
        if (!$channel instanceof ChannelInterface) {
            return self::errorResponse(
                404,
                'Not Found',
                sprintf('Unknown notification channel type: %s', $type),
            );
        }

        $account = $request->attributes->get('_account');
        $recipient = self::buildTestRecipient($account);
        $notification = self::buildTestNotification();

        try {
            $channel->send($recipient, $notification);
        } catch (\Throwable $e) {
            return new JsonResponse(
                [
                    'type' => $type,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'exception_class' => $e::class,
                ],
                500,
                ['Content-Type' => 'application/vnd.api+json'],
            );
        }

        return new JsonResponse(
            [
                'type' => $type,
                'status' => 'success',
                'message' => 'Test sent.',
            ],
            200,
            ['Content-Type' => 'application/vnd.api+json'],
        );
    }

    /**
     * Build a `NotifiableInterface` whose routing reads from the requesting
     * admin's account. For `mail`, we surface the admin's email (or a sentinel
     * if none is available); for any other channel, we surface the account id
     * stringified — the channel implementation chooses what to do with it.
     */
    private static function buildTestRecipient(mixed $account): NotifiableInterface
    {
        $email = null;
        $accountId = null;
        if (is_object($account)) {
            if (method_exists($account, 'getEmail')) {
                $candidate = $account->getEmail();
                if (is_string($candidate) && $candidate !== '') {
                    $email = $candidate;
                }
            }
            if (method_exists($account, 'id')) {
                $candidate = $account->id();
                if (is_int($candidate) || (is_string($candidate) && $candidate !== '')) {
                    $accountId = (string) $candidate;
                }
            }
        }
        $email ??= 'admin@example.invalid';
        $accountId ??= '0';

        return new class ($email, $accountId) implements NotifiableInterface {
            public function __construct(
                private readonly string $email,
                private readonly string $accountId,
            ) {}

            public function routeNotificationFor(string $channel): mixed
            {
                return match ($channel) {
                    'mail' => $this->email,
                    'database' => $this->accountId,
                    default => $this->accountId,
                };
            }

            public function getNotifiableId(): string
            {
                return $this->accountId;
            }

            public function getNotifiableType(): string
            {
                return 'admin_test';
            }
        };
    }

    private static function buildTestNotification(): NotificationInterface
    {
        return new class implements NotificationInterface {
            public function via(NotifiableInterface $notifiable): array
            {
                // The controller looks up exactly one channel and calls
                // `send()` directly, so `via()` is never consulted by the
                // dispatcher in this path. Returning every common name keeps
                // the notification usable if a future caller decides to feed
                // it through `NotificationDispatcher::send()` instead.
                return ['mail', 'database'];
            }

            public function toArray(NotifiableInterface $notifiable): array
            {
                return [
                    'subject' => '[Waaseyaa test]',
                    'message' => 'This is a test from /notifications — no action required.',
                ];
            }

            /**
             * The mail channel calls `toMail()` via `method_exists()` and
             * forwards the return to `MailerInterface::send()`, which
             * type-hints `Waaseyaa\Mail\Envelope`. Build a minimal envelope
             * addressed to the requesting admin.
             */
            public function toMail(NotifiableInterface $notifiable): Envelope
            {
                $route = $notifiable->routeNotificationFor('mail');
                $to = is_string($route) && $route !== '' ? [$route] : ['admin@example.invalid'];

                return new Envelope(
                    to: $to,
                    from: 'noreply@waaseyaa.local',
                    subject: '[Waaseyaa test]',
                    textBody: 'This is a test from /notifications — no action required.',
                );
            }
        };
    }

    private static function errorResponse(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => (string) $status,
                'title' => $title,
                'detail' => $detail,
            ]],
        ], $status, ['Content-Type' => 'application/vnd.api+json']);
    }
}
