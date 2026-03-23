<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envExample = $root . '/.env.example';
$envFile = $root . '/.env';

if (!file_exists($envFile) && file_exists($envExample)) {
    $content = file_get_contents($envExample);
    $secret = bin2hex(random_bytes(32));
    $content = str_replace('WAASEYAA_JWT_SECRET=', "WAASEYAA_JWT_SECRET={$secret}", $content);
    file_put_contents($envFile, $content);
}

echo "\n";
echo "  \033[32mWaaseyaa project ready.\033[0m\n";
echo "\n";
echo "  \033[33mbin/waaseyaa serve\033[0m    Start the dev server\n";
echo "  \033[33mbin/waaseyaa\033[0m          See all commands\n";
echo "\n";
