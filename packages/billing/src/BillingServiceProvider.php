<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(BillingManager::class, fn() => new BillingManager(
            stripe: $this->resolve(StripeClientInterface::class),
            priceTierMap: $this->config['billing_price_tier_map'] ?? [],
            successUrl: $this->config['billing_success_url'] ?? '/',
            cancelUrl: $this->config['billing_cancel_url'] ?? '/',
            portalReturnUrl: $this->config['billing_portal_return_url'] ?? '/',
            foundingMemberCap: (int) ($this->config['billing_founding_member_cap'] ?? 100),
        ));

        $this->singleton(WebhookHandler::class, fn() => new WebhookHandler(
            stripe: $this->resolve(StripeClientInterface::class),
        ));
    }
}
