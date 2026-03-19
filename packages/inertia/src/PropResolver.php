<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

final class PropResolver
{
    public static function optional(\Closure $callback): OptionalProp
    {
        return new OptionalProp($callback);
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string> $only
     * @param list<string> $except
     * @return array<string, mixed>
     */
    public function resolve(array $props, array $only = [], array $except = []): array
    {
        $resolved = [];

        foreach ($props as $key => $value) {
            if ($except !== [] && in_array($key, $except, true)) {
                continue;
            }

            if ($only !== [] && !in_array($key, $only, true)) {
                continue;
            }

            if ($value instanceof OptionalProp) {
                if ($only !== [] && in_array($key, $only, true)) {
                    $resolved[$key] = ($value->callback)();
                }
                continue;
            }

            $resolved[$key] = $value instanceof \Closure ? $value() : $value;
        }

        return $resolved;
    }
}
