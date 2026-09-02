<?php

declare(strict_types=1);

namespace Fixture\CustomField;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class CustomFieldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(CustomProduct::class, group: 'fixture'));
    }
}
