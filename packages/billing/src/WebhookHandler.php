<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

final class WebhookHandler
{
    public function __construct(
        private readonly StripeClientInterface $stripe,
    ) {}

    /**
     * Process a Stripe webhook event.
     *
     * @return array<string, mixed>|null Structured event data, or null for unhandled events
     */
    public function handle(string $payload, string $signature): ?array
    {
        $event = $this->stripe->constructWebhookEvent($payload, $signature);
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        return match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($type, $object),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionEvent($type, $object),
            'invoice.payment_succeeded' => $this->handleInvoiceSucceeded($type, $object),
            'invoice.payment_failed' => $this->handleInvoiceFailed($type, $object),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function handleCheckoutCompleted(string $type, array $object): array
    {
        return [
            'event' => $type,
            'customer_id' => $object['customer'] ?? null,
            'subscription_id' => $object['subscription'] ?? null,
            'metadata' => $object['metadata'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function handleSubscriptionEvent(string $type, array $object): array
    {
        $priceId = $object['items']['data'][0]['price']['id'] ?? null;

        return [
            'event' => $type,
            'subscription_id' => $object['id'] ?? null,
            'customer_id' => $object['customer'] ?? null,
            'status' => $object['status'] ?? null,
            'price_id' => $priceId,
        ];
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function handleInvoiceSucceeded(string $type, array $object): array
    {
        return [
            'event' => $type,
            'customer_id' => $object['customer'] ?? null,
            'subscription_id' => $object['subscription'] ?? null,
            'amount_paid' => $object['amount_paid'] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function handleInvoiceFailed(string $type, array $object): array
    {
        return [
            'event' => $type,
            'customer_id' => $object['customer'] ?? null,
            'subscription_id' => $object['subscription'] ?? null,
            'amount_due' => $object['amount_due'] ?? 0,
        ];
    }
}
