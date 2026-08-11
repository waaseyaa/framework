<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Upgrade;

/**
 * Loads the versioned contract shipped inside the Foundation split package.
 *
 * @api
 */
final class UpgradePreflightContract
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = dirname(__DIR__, 2) . '/resources/upgrade/s1-v1.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Bundled upgrade contract is unreadable: %s', $path));
        }

        try {
            $contract = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Bundled upgrade contract is invalid JSON.', 0, $exception);
        }

        if (!is_array($contract) || array_is_list($contract)) {
            throw new \RuntimeException('Bundled upgrade contract must be a JSON object.');
        }

        return $contract;
    }
}
