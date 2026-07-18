<?php

declare(strict_types=1);

namespace App;

use Waaseyaa\Entity\EntityBase;

final class ProfileEntity extends EntityBase
{
    public function __construct(array $values = [])
    {
        parent::__construct($values, 'app_profile', ['id' => 'id']);
    }
}
