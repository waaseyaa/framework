<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Flash;

final class Flash
{
    private static ?FlashMessageService $service = null;

    public static function setService(FlashMessageService $service): void
    {
        self::$service = $service;
    }

    /** Reset for test isolation. */
    public static function resetService(): void
    {
        self::$service = null;
    }

    public static function success(string $message): void
    {
        self::getService()->addSuccess($message);
    }

    public static function error(string $message): void
    {
        self::getService()->addError($message);
    }

    public static function info(string $message): void
    {
        self::getService()->addInfo($message);
    }

    private static function getService(): FlashMessageService
    {
        if (self::$service === null) {
            self::$service = new FlashMessageService();
        }

        return self::$service;
    }
}
