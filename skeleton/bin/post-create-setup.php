<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envExample = $root . '/.env.example';
$envFile = $root . '/.env';

if (!file_exists($envFile) && file_exists($envExample)) {
    $content = file_get_contents($envExample);
    $secret = bin2hex(random_bytes(32));
    $appName = ucwords(str_replace(['-', '_'], ' ', basename($root)));
    if (str_contains($appName, ' ')) {
        $appName = '"' . $appName . '"';
    }
    $content = str_replace('WAASEYAA_JWT_SECRET=', "WAASEYAA_JWT_SECRET={$secret}", $content);
    if (str_contains($content, 'APP_NAME=Waaseyaa')) {
        $content = str_replace('APP_NAME=Waaseyaa', "APP_NAME={$appName}", $content);
    } else {
        fwrite(STDERR, "Warning: Could not set APP_NAME — placeholder not found in .env.example.\n");
    }
    file_put_contents($envFile, $content);
}

// Make the maintenance scripts executable on POSIX. PHP's chmod is a harmless
// no-op on Windows (executability is extension-based there), so this runs
// cross-platform — unlike the shell `chmod` that previously ran as the first
// post-create-project step and aborted `composer create-project` on Windows
// before .env was ever generated. (#1628)
foreach (glob($root . '/bin/maintenance/*') ?: [] as $maintenanceScript) {
    if (is_file($maintenanceScript)) {
        @chmod($maintenanceScript, 0o755);
    }
}

/**
 * @return array{0: bool, 1: string}
 */
function adminPackageStatus(string $projectRoot): array
{
    $adminPath = getenv('WAASEYAA_ADMIN_PATH');
    if (!is_string($adminPath) || $adminPath === '') {
        $composer = $projectRoot . '/composer.json';
        if (is_file($composer)) {
            $decoded = json_decode((string) file_get_contents($composer), true);
            $adminPath = is_array($decoded) ? ($decoded['extra']['waaseyaa']['admin_path'] ?? '') : '';
        }
    }

    if (!is_string($adminPath) || $adminPath === '') {
        return [false, 'No admin package configured yet.'];
    }

    $absolute = str_starts_with($adminPath, '/') ? $adminPath : $projectRoot . '/' . $adminPath;
    if (is_file($absolute . '/package.json')) {
        return [true, 'Admin package detected; composer run dev will start HMR.'];
    }

    return [false, 'Admin package path configured but package.json is missing.'];
}

echo "\n";
$dir = basename($root);
[$hasAdminPackage, $adminStatus] = adminPackageStatus($root);

echo "  \033[32mWaaseyaa project ready.\033[0m\n";
echo "\n";
echo "  \033[33mcd {$dir}\033[0m\n";
echo "  \033[33mcomposer run dev\033[0m      Start backend (and admin HMR when configured)\n";
echo "  \033[33m./vendor/bin/waaseyaa list\033[0m  See all commands\n";
echo "\n";
echo "  {$adminStatus}\n";
if (!$hasAdminPackage) {
    echo "  Optional: set \033[33mWAASEYAA_ADMIN_PATH\033[0m to a Nuxt admin package for live-reload UI.\n";
}
echo "\n";
