<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

interface StripeClientInterface
{
    /**
     * Create a Stripe Checkout Session.
     *
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(array $params): CheckoutSession;

    /**
     * Create a Stripe Customer Portal session and return the URL.
     */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Verify a webhook signature and return the parsed event payload.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException If signature verification fails
     */
    public function constructWebhookEvent(string $payload, string $signature): array;
}
