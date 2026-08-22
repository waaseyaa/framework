<?php

declare(strict_types=1);

/**
 * Test-only adversarial fixture: retain the previous request's mark in a
 * process-static so the worker-runtime lane can prove it goes red.
 *
 * Never loaded in production. The green acceptance run does not include this
 * file. Do not copy this pattern into production package src trees.
 */
if (!class_exists('WaaseyaaFrankenphpAcceptanceLeakStore', false)) {
    final class WaaseyaaFrankenphpAcceptanceLeakStore
    {
        public static ?string $previousMark = null;

        public static ?string $previousCommunity = null;
    }
}

$currentMark = $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_MARK'] ?? '';
$currentCommunity = $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] ?? '';
$emitHeaders = \PHP_SAPI !== 'cli';

if ($emitHeaders && is_string(WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark) && WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark !== '') {
    header('X-Waaseyaa-Leak-Previous: ' . WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark);
}

if ($emitHeaders && is_string(WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity) && WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity !== '') {
    header('X-Waaseyaa-Leak-Community: ' . WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity);
}

if (is_string($currentMark) && $currentMark !== '') {
    WaaseyaaFrankenphpAcceptanceLeakStore::$previousMark = $currentMark;
}

if (is_string($currentCommunity) && $currentCommunity !== '') {
    WaaseyaaFrankenphpAcceptanceLeakStore::$previousCommunity = $currentCommunity;
}
