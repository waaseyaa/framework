<?php

declare(strict_types=1);

namespace Waaseyaa\AdminBridge;

final class AdminSpaController
{
    public function __construct(
        private readonly string $adminPath,
    ) {}

    public function __invoke(): string
    {
        $indexFile = $this->adminPath . '/index.html';

        if (!is_file($indexFile)) {
            header('Content-Type: application/json', true, 404);
            return json_encode([
                'errors' => [['status' => '404', 'title' => 'Admin SPA index.html not found']],
            ], JSON_THROW_ON_ERROR);
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        return file_get_contents($indexFile);
    }
}
