<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\AdminSurface\AdminSpaFallback;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;

/** Reads the current app/package SPA assets only when the shell route executes. */
final readonly class AdminSpaHttpController
{
    public function __construct(private string $projectRoot, private string $vendorDistDir, private string $csrfCookieName) {}

    public function serve(mixed $request = null, string $path = ''): Response
    {
        if ($request instanceof Request && $request->attributes->has('path')) {
            $matchedPath = $request->attributes->get('path');
            if (!is_string($matchedPath)) {
                throw new \InvalidArgumentException('Invalid matched Admin SPA path.');
            }
            $path = $matchedPath;
        }
        if ($path !== '' && !str_contains($path, '..')) {
            $publicAsset = $this->projectRoot . '/public/admin/' . $path;
            if (is_file($publicAsset)) {
                return AdminSurfaceServiceProvider::serveStaticFile($publicAsset, $this->csrfCookieName);
            }
            $vendorAsset = $this->vendorDistDir . '/' . $path;
            if (is_file($vendorAsset)) {
                return AdminSurfaceServiceProvider::serveStaticFile($vendorAsset, $this->csrfCookieName);
            }
        }
        $vendorIndex = $this->vendorDistDir . '/index.html';
        $vendorContent = is_file($vendorIndex) ? file_get_contents($vendorIndex) : null;
        if ($vendorContent === false) {
            throw new \RuntimeException('The packaged Admin SPA index is unreadable.');
        }
        $html = AdminSurfaceServiceProvider::resolveAdminIndex($this->projectRoot, $vendorContent);
        if ($html !== null) {
            return new Response(AdminSurfaceServiceProvider::applyRuntimeCsrfCookieName($html, $this->csrfCookieName), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $appName = getenv('APP_NAME');
        $appName = is_string($appName) && $appName !== '' ? $appName : 'Application';
        return AdminSpaFallback::htmlResponse($appName);
    }
}
