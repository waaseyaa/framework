<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
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
        private readonly AccountFieldReadScopeInterface $fieldReadScope,
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

        /** @var Response $response */
        $response = $this->fieldReadScope->run(
            $principal,
            fn(): Response => $this->handleAuthorized($id, $principal),
        );

        return $response;
    }

    private function handleAuthorized(string $id, AuthorizationPrincipalInterface $principal): Response
    {
        $media = $id !== '' ? $this->entityTypeManager->getRepository('media')->find($id) : null;

        if (
            !$media instanceof Media
            || !$this->accessHandler->check($media, 'view', $principal)->isAllowed()
        ) {
            return $this->notFound();
        }

        $path = $this->resolvePublicPath((string) $media->get('source_uri'));
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

        return new StreamedResponse(
            static function () use ($path): void {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    return;
                }
                fpassthru($handle);
                fclose($handle);
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $safeFilename),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
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
