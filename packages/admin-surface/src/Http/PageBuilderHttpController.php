<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Http;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\DecisionAccountResolver;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;

/** Preserves the page-builder host's principal, body and refusal wire contract. */
final readonly class PageBuilderHttpController
{
    public function __construct(private PageBuilderSurfaceHostInterface $host) {}

    /** @return array<string, mixed> */
    public function definitions(Request $request, mixed $surface = null): array
    {
        return self::response($this->host->handleDefinitions(self::request($request), self::parameter($request, 'surface', $surface)));
    }

    /** @return array<string, mixed> */
    public function command(Request $request, mixed $surface = null, mixed $id = null): array
    {
        return self::response($this->host->handleCommand(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id)));
    }

    /** @return array<string, mixed> */
    public function preview(Request $request, mixed $surface = null, mixed $id = null): array
    {
        return self::response($this->host->handlePreview(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id)));
    }

    /** @return array<string, mixed> */
    public function history(Request $request, mixed $surface = null, mixed $id = null): array
    {
        return self::response($this->host->handleHistory(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id)));
    }

    /** @return array<string, mixed> */
    public function revision(Request $request, mixed $surface = null, mixed $id = null, mixed $revision = null): array
    {
        return self::response($this->host->handleRevision(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id), self::parameter($request, 'revision', $revision)));
    }

    /** @return array<string, mixed> */
    public function restore(Request $request, mixed $surface = null, mixed $id = null): array
    {
        return self::response($this->host->handleRestore(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id)));
    }

    /** @return array<string, mixed> */
    public function draft(Request $request, mixed $surface = null, mixed $id = null): array
    {
        return self::response($this->host->handleDraft(self::request($request), self::parameter($request, 'surface', $surface), self::parameter($request, 'id', $id)));
    }

    /** @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private static function response(array $envelope): array
    {
        $status = $envelope['error']['status'] ?? null;
        if (($envelope['ok'] ?? null) !== false
            || !is_int($status)
            || $status < 400
            || $status > 599
            || array_key_exists('statusCode', $envelope)
            || array_key_exists('body', $envelope)
        ) {
            return $envelope;
        }

        return ['statusCode' => $status, 'body' => $envelope];
    }

    private static function request(Request $request): PageBuilderSurfaceRequest
    {
        return new PageBuilderSurfaceRequest(
            DecisionAccountResolver::resolve(
                $request->attributes->get('_authorization_principal'),
                $request->attributes->get('_account'),
            ),
            $request->getContent(),
        );
    }

    private static function parameter(Request $request, string $name, mixed $fallback): string
    {
        $value = $request->attributes->get($name, $fallback);
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Invalid matched Admin Surface parameter.');
        }
        return $value;
    }
}
