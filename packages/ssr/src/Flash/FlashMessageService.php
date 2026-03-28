<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Flash;

final class FlashMessageService
{
    private const string SESSION_KEY = 'flash_messages';

    public function addSuccess(string $message): void
    {
        $this->add('success', $message);
    }

    public function addError(string $message): void
    {
        $this->add('error', $message);
    }

    public function addInfo(string $message): void
    {
        $this->add('info', $message);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function consumeAll(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return [];
        }

        $messages = $_SESSION[self::SESSION_KEY];
        unset($_SESSION[self::SESSION_KEY]);

        $allowedTypes = ['success', 'error', 'info'];

        return array_values(array_filter($messages, static function (mixed $msg) use ($allowedTypes): bool {
            return is_array($msg)
                && isset($msg['type'], $msg['message'])
                && is_string($msg['type'])
                && is_string($msg['message'])
                && $msg['message'] !== ''
                && in_array($msg['type'], $allowedTypes, true);
        }));
    }

    private function add(string $type, string $message): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][] = ['type' => $type, 'message' => $message];
    }
}
