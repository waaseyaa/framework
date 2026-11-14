<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class LegacyProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(SyntheticPost::class, group: 'content'));

    }
}
