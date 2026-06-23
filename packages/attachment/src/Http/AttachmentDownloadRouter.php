<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Attachment\Attachment;
use Waaseyaa\Attachment\Storage\PrivateFileStore;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;

/**
 * Authorized download of a private attachment's bytes.
 *
 * `GET /attachment/{id}/download` (controller `attachment.download`). Entity-row
 * access policies protect the attachment RECORD; this is the only path that
 * serves its BYTES, and it enforces the SAME access decision before streaming:
 * deny-by-default (`isAllowed()`), fail-closed, and 404-on-deny so the endpoint
 * cannot be used as an existence oracle.
 *
 * Attachment view access delegates to the parent entity (ParentDelegatedAccessPolicy),
 * so a download is permitted exactly when the caller may view the parent. Bytes
 * are read only from the `private://` root (outside any web-served directory) via
 * {@see PrivateFileStore}; a non-`private://` `storage_uri` (e.g. a legitimately
 * public asset) or missing bytes resolve to 404 here — public assets are served
 * by the host's normal static path, not through this authorized route.
 */
final class AttachmentDownloadRouter implements DomainRouterInterface
{
    public const string CONTROLLER = 'attachment.download';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EntityAccessHandler $accessHandler,
        private readonly PrivateFileStore $privateStore,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller') === self::CONTROLLER;
    }

    public function handle(Request $request): Response
    {
        $id = (string) $request->attributes->get('id', '');
        $account = $request->attributes->get('_account');

        $attachment = $id !== ''
            ? $this->entityTypeManager->getStorage('attachment')->load($id)
            : null;

        // Deny-by-default, fail-closed: anything we can't prove viewable → 404
        // (never 403 — that would leak existence). No account bound, no such
        // attachment, or the view check is not Allowed all return the same 404.
        if (
            !$attachment instanceof Attachment
            || !$account instanceof AccountInterface
            || !$this->accessHandler->check($attachment, 'view', $account)->isAllowed()
        ) {
            return $this->notFound();
        }

        $path = $this->privateStore->resolve((string) $attachment->get('storage_uri'));
        if ($path === null) {
            // Not a private-scheme file, escapes the root, or bytes are missing.
            return $this->notFound();
        }

        $filename = $this->safeFilename((string) $attachment->get('filename'));
        $contentType = (string) $attachment->get('content_type');
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        return new StreamedResponse(
            static function () use ($path): void {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    return;
                }
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                }
                fclose($handle);
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function safeFilename(string $filename): string
    {
        $base = basename($filename);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        return ($clean === null || $clean === '') ? 'download' : $clean;
    }

    private function notFound(): Response
    {
        return new Response('Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
