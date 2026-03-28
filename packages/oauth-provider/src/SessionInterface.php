<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

interface SessionInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
}
