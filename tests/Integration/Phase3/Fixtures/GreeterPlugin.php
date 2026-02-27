<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Phase3\Fixtures;

use Aurora\Plugin\Attribute\AuroraPlugin;
use Aurora\Plugin\PluginBase;

#[AuroraPlugin(id: 'greeter', label: 'Greeter', description: 'A greeting plugin for integration testing')]
final class GreeterPlugin extends PluginBase
{
    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}
