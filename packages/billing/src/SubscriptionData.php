<?php

declare(strict_types=1);

namespace Waaseyaa\Billing;

final readonly class SubscriptionData
{
    private const array ACTIVE_STATUSES = ['active', 'trialing'];

    public function __construct(
        public string $stripeId,
        public string $stripeStatus,
        public string $stripePrice,
        public int $quantity,
        public ?int $trialEndsAt,
        public ?int $endsAt,
    ) {}

    public function isActive(): bool
    {
        return in_array($this->stripeStatus, self::ACTIVE_STATUSES, true);
    }

    public function hasPrice(string $priceId): bool
    {
        return $this->stripePrice === $priceId;
    }

    /**
     * @param list<string> $priceIds
     */
    public function hasAnyPrice(array $priceIds): bool
    {
        return in_array($this->stripePrice, $priceIds, true);
    }
}
