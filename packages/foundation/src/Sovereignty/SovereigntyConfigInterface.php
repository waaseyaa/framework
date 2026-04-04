<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Sovereignty;

interface SovereigntyConfigInterface
{
    public function get(string $key): ?string;

    public function getProfile(): SovereigntyProfile;

    /** @return array<string, string> */
    public function all(): array;
}
