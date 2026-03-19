<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

final class FakeStripeClient implements StripeClientInterface
{
    private CheckoutSession $nextCheckoutSession;
    private string $nextPortalUrl = 'https://billing.stripe.com/portal/fake';
    /** @var array<string, mixed> */
    private array $nextWebhookEvent = [];

    public function __construct()
    {
        $this->nextCheckoutSession = new CheckoutSession('cs_fake', 'https://checkout.stripe.com/fake');
    }

    public function setNextCheckoutSession(CheckoutSession $session): void
    {
        $this->nextCheckoutSession = $session;
    }

    public function setNextPortalUrl(string $url): void
    {
        $this->nextPortalUrl = $url;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function setNextWebhookEvent(array $event): void
    {
        $this->nextWebhookEvent = $event;
    }

    public function createCheckoutSession(array $params): CheckoutSession
    {
        return $this->nextCheckoutSession;
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        return $this->nextPortalUrl;
    }

    public function constructWebhookEvent(string $payload, string $signature): array
    {
        if ($this->nextWebhookEvent === []) {
            throw new \RuntimeException('No fake webhook event configured');
        }

        return $this->nextWebhookEvent;
    }
}
