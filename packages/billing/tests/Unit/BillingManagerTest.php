<?php

declare(strict_types=1);

namespace Waaseyaa\Billing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Billing\BillingManager;
use Waaseyaa\Billing\CheckoutSession;
use Waaseyaa\Billing\FakeStripeClient;
use Waaseyaa\Billing\PlanTier;
use Waaseyaa\Billing\SubscriptionData;

#[CoversClass(BillingManager::class)]
final class BillingManagerTest extends TestCase
{
    private FakeStripeClient $stripe;
    private BillingManager $billing;

    protected function setUp(): void
    {
        $this->stripe = new FakeStripeClient();
        $this->billing = new BillingManager(
            stripe: $this->stripe,
            priceTierMap: [
                'price_growth_monthly' => 'growth',
                'price_growth_yearly' => 'growth',
                'price_business_monthly' => 'business',
                'price_business_yearly' => 'business',
                'price_pro_monthly' => 'pro',
                'price_pro_yearly' => 'pro',
            ],
            successUrl: 'https://app.test/billing?success=true',
            cancelUrl: 'https://app.test/billing?canceled=true',
            portalReturnUrl: 'https://app.test/billing',
            foundingMemberCap: 100,
        );
    }

    public function testCreateCheckoutSessionReturnsSession(): void
    {
        $expected = new CheckoutSession('cs_123', 'https://checkout.stripe.com/cs_123');
        $this->stripe->setNextCheckoutSession($expected);

        $session = $this->billing->createCheckoutSession('cus_abc', 'price_pro_monthly');

        $this->assertSame('cs_123', $session->id);
        $this->assertSame('https://checkout.stripe.com/cs_123', $session->url);
    }

    public function testGetPortalUrlReturnsUrl(): void
    {
        $this->stripe->setNextPortalUrl('https://billing.stripe.com/portal/real');

        $url = $this->billing->getPortalUrl('cus_abc');

        $this->assertSame('https://billing.stripe.com/portal/real', $url);
    }

    public function testResolveTierFromPlanOverride(): void
    {
        $tier = $this->billing->resolveUserTier(
            planOverride: 'enterprise',
            subscriptions: [],
        );

        $this->assertSame(PlanTier::Enterprise, $tier);
    }

    public function testResolveTierFoundingMapsToBusiness(): void
    {
        $tier = $this->billing->resolveUserTier(
            planOverride: 'founding',
            subscriptions: [],
        );

        $this->assertSame(PlanTier::Business, $tier);
    }

    public function testResolveTierFromActiveSubscription(): void
    {
        $sub = new SubscriptionData('sub_1', 'active', 'price_growth_monthly', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [$sub],
        );

        $this->assertSame(PlanTier::Growth, $tier);
    }

    public function testResolveTierIgnoresCanceledSubscription(): void
    {
        $sub = new SubscriptionData('sub_1', 'canceled', 'price_growth_monthly', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [$sub],
        );

        $this->assertSame(PlanTier::Free, $tier);
    }

    public function testResolveTierPlanOverrideTakesPrecedenceOverSubscription(): void
    {
        $sub = new SubscriptionData('sub_1', 'active', 'price_pro_monthly', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: 'enterprise',
            subscriptions: [$sub],
        );

        $this->assertSame(PlanTier::Enterprise, $tier);
    }

    public function testResolveTierDefaultsToFree(): void
    {
        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [],
        );

        $this->assertSame(PlanTier::Free, $tier);
    }

    public function testResolveTierInvalidOverrideIgnored(): void
    {
        $tier = $this->billing->resolveUserTier(
            planOverride: 'garbage',
            subscriptions: [],
        );

        $this->assertSame(PlanTier::Free, $tier);
    }

    public function testResolveTierHighestSubscriptionWins(): void
    {
        $proSub = new SubscriptionData('sub_1', 'active', 'price_pro_monthly', 1, null, null);
        $growthSub = new SubscriptionData('sub_2', 'active', 'price_growth_monthly', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [$proSub, $growthSub],
        );

        $this->assertSame(PlanTier::Growth, $tier);
    }

    public function testFoundingMemberSlotsRemaining(): void
    {
        $remaining = $this->billing->foundingMemberSlotsRemaining(currentCount: 42);

        $this->assertSame(58, $remaining);
    }

    public function testFoundingMemberSlotsRemainingNeverNegative(): void
    {
        $remaining = $this->billing->foundingMemberSlotsRemaining(currentCount: 200);

        $this->assertSame(0, $remaining);
    }

    public function testCanGrantFoundingMembership(): void
    {
        $this->assertTrue($this->billing->canGrantFoundingMembership(currentCount: 99));
        $this->assertFalse($this->billing->canGrantFoundingMembership(currentCount: 100));
    }

    public function testResolveTierTrialingCountsAsActive(): void
    {
        $sub = new SubscriptionData('sub_1', 'trialing', 'price_pro_monthly', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [$sub],
        );

        $this->assertSame(PlanTier::Pro, $tier);
    }

    public function testResolveTierUnknownPriceDefaultsToFree(): void
    {
        $sub = new SubscriptionData('sub_1', 'active', 'price_unknown', 1, null, null);

        $tier = $this->billing->resolveUserTier(
            planOverride: null,
            subscriptions: [$sub],
        );

        $this->assertSame(PlanTier::Free, $tier);
    }
}
