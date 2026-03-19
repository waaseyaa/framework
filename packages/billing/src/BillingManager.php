<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

final class BillingManager
{
    /** @var array<string, int> tier priority (higher = better) */
    private const array TIER_PRIORITY = [
        'free' => 0,
        'pro' => 1,
        'business' => 2,
        'growth' => 3,
        'enterprise' => 4,
    ];

    /**
     * @param array<string, string> $priceTierMap Maps Stripe price IDs to tier names
     */
    public function __construct(
        private readonly StripeClientInterface $stripe,
        private readonly array $priceTierMap,
        private readonly string $successUrl,
        private readonly string $cancelUrl,
        private readonly string $portalReturnUrl,
        private readonly int $foundingMemberCap = 100,
    ) {
    }

    /**
     * Create a Stripe Checkout Session for a subscription.
     */
    public function createCheckoutSession(string $stripeCustomerId, string $priceId): CheckoutSession
    {
        return $this->stripe->createCheckoutSession([
            'customer' => $stripeCustomerId,
            'mode' => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
        ]);
    }

    /**
     * Get the Stripe Customer Portal URL.
     */
    public function getPortalUrl(string $stripeCustomerId): string
    {
        return $this->stripe->createPortalSession($stripeCustomerId, $this->portalReturnUrl);
    }

    /**
     * Resolve a user's plan tier from their override and subscriptions.
     *
     * Priority:
     * 1. plan_override (admin-set; "founding" -> business)
     * 2. Highest active subscription tier
     * 3. Default: free
     *
     * @param list<SubscriptionData> $subscriptions
     */
    public function resolveUserTier(?string $planOverride, array $subscriptions): PlanTier
    {
        if ($planOverride !== null && $planOverride !== '') {
            $tier = PlanTier::fromString($planOverride);
            if ($tier !== PlanTier::Free || PlanTier::isValid($planOverride)) {
                return $tier;
            }
        }

        $highestPriority = -1;
        $highestTier = PlanTier::Free;

        foreach ($subscriptions as $sub) {
            if (!$sub->isActive()) {
                continue;
            }

            $tierName = $this->priceTierMap[$sub->stripePrice] ?? null;
            if ($tierName === null) {
                continue;
            }

            $priority = self::TIER_PRIORITY[$tierName] ?? 0;
            if ($priority > $highestPriority) {
                $highestPriority = $priority;
                $highestTier = PlanTier::fromString($tierName);
            }
        }

        return $highestTier;
    }

    /**
     * Calculate remaining founding member slots.
     */
    public function foundingMemberSlotsRemaining(int $currentCount): int
    {
        return max(0, $this->foundingMemberCap - $currentCount);
    }

    /**
     * Check if a founding membership can be granted.
     */
    public function canGrantFoundingMembership(int $currentCount): bool
    {
        return $this->foundingMemberSlotsRemaining($currentCount) > 0;
    }
}
