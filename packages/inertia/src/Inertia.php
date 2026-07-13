<?php

declare(strict_types=1);

namespace Waaseyaa\Inertia;

/**
 * @api
 */
final class Inertia
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    private static string $version = '';

    private static ?RootTemplateRenderer $renderer = null;

    public static function setVersion(string $version): void
    {
        self::$version = $version;
    }

    public static function getVersion(): string
    {
        return self::$version;
    }

    public static function setRenderer(RootTemplateRenderer $renderer): void
    {
        self::$renderer = $renderer;
    }

    public static function getRenderer(): RootTemplateRenderer
    {
        return self::$renderer ?? new RootTemplateRenderer();
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function clearShared(): void
    {
        self::$shared = [];
    }

    /** @param array<string, mixed> $props */
    public static function render(
        string $component,
        array $props,
        bool $encryptHistory = false,
        bool $clearHistory = false,
    ): InertiaResponse {
        $mergedProps = self::resolveSharedProps();

        foreach ($props as $key => $value) {
            $mergedProps[$key] = $value;
        }

        return new InertiaResponse(
            component: $component,
            props: $mergedProps,
            url: '',
            version: self::$version,
            encryptHistory: $encryptHistory,
            clearHistory: $clearHistory,
        );
    }

    /** @return array<string, mixed> */
    private static function resolveSharedProps(): array
    {
        $resolved = [];

        foreach (self::$shared as $key => $value) {
            $resolved[$key] = $value instanceof \Closure ? $value() : $value;
        }

        return $resolved;
    }

    public static function reset(): void
    {
        self::clearShared();
        self::$version = '';
        self::$renderer = null;
    }
}
