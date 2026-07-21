<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Media\Http\MediaDownloadSourceReaderInterface;
use Waaseyaa\Media\Media;

// Symfony HTTP types are required by the L0 DomainRouterInterface boundary; allowlisted with the sibling upload router.
/** Authorized, fail-closed delivery of a media entity's public:// bytes. */
final class MediaDownloadRouter implements DomainRouterInterface
{
    public const string CONTROLLER = 'media.download';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly string $filesRoot,
        private readonly MediaDownloadSourceReaderInterface $sourceReader,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === self::CONTROLLER;
    }

    public function handle(Request $request): Response
    {
        $id = (string) $request->attributes->get('id', '');
        $account = $request->attributes->get('_account');
        $principal = $request->attributes->get('_authorization_principal');

        if (!$account instanceof AccountInterface
            || !$principal instanceof AuthorizationPrincipalInterface
            || (string) $principal->id() !== (string) $account->id()
        ) {
            return $this->notFound();
        }

        return $this->handleAuthorized($id, $principal, $request);
    }

    private function handleAuthorized(string $id, AuthorizationPrincipalInterface $principal, Request $request): Response
    {
        // This endpoint deliberately serves one complete representation. Do
        // not let a client Range header reach a downstream worker/server layer
        // that may attempt a partial response after authorization has passed.
        // The response advertises Accept-Ranges: none below and remains 200.
        // This is especially important for browser download navigations.
        // (The request object is local to this authorized delivery path.)
        $request->headers->remove('Range');
        $media = $id !== '' ? $this->entityTypeManager->getRepository('media')->find($id) : null;

        if (
            !$media instanceof Media
            || !$this->accessHandler->check($media, 'view', $principal)->isAllowed()
        ) {
            return $this->notFound();
        }

        $path = $this->resolvePublicPath($this->sourceReader->sourceUri($media, $principal));
        if ($path === null) {
            return $this->notFound();
        }

        $detectedContentType = new \finfo(FILEINFO_MIME_TYPE)->file($path);
        $contentType = is_string($detectedContentType) && $detectedContentType !== ''
            ? $detectedContentType
            : 'application/octet-stream';
        $filename = basename($path);
        $sanitizedFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename));
        $safeFilename = $sanitizedFilename !== null && $sanitizedFilename !== '' ? $sanitizedFilename : 'download';
        $fileSize = filesize($path);
        $isDocumentNavigation = strtolower((string) $request->headers->get('Sec-Fetch-Dest')) === 'document'
            && strtolower((string) $request->headers->get('Sec-Fetch-Mode')) === 'navigate';
        $disposition = $isDocumentNavigation ? 'inline' : 'attachment';
        $headers = [
            'Content-Type' => $contentType,
            // A real document navigation must remain a renderer response.
            // Turning it into a browser download aborts the navigation; some
            // browser-extension drivers surface that abort as a synthetic 503
            // even though FrankenPHP emitted a complete 200 response.
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $safeFilename),
            'X-Content-Type-Options' => 'nosniff',
            // Serve one complete authorized representation and prevent
            // browser download managers from retrying it as parallel ranges.
            // A Range request still receives this complete 200 response.
            'Accept-Ranges' => 'none',
        ];
        if (is_int($fileSize)) {
            $headers['Content-Length'] = (string) $fileSize;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return $this->notFound();
        }

        // A normal response is intentional here. FrankenPHP's worker can
        // emit StreamedResponse callbacks differently for a top-level browser
        // download navigation than for fetch/XHR. The bytes have already been
        // authorized and path-confined, so buffering this protected document
        // gives every browser request the same complete 200 representation.
        return new Response($bytes, 200, $headers);
    }

    private function resolvePublicPath(string $uri): ?string
    {
        if (!str_starts_with($uri, 'public://')) {
            return null;
        }
        $relative = substr($uri, strlen('public://'));
        if ($relative === '' || str_contains($relative, "\0")) {
            return null;
        }
        $segments = preg_split('~[\\\\/]~', $relative);
        if ($segments === false || in_array('..', $segments, true)) {
            return null;
        }

        $root = realpath($this->filesRoot);
        $path = realpath(rtrim($this->filesRoot, '/\\') . DIRECTORY_SEPARATOR . ltrim($relative, '/\\'));
        if ($root === false || $path === false || !is_file($path)) {
            return null;
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? $path : null;
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
