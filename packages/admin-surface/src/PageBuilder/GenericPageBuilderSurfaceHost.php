<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\PageBuilder;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\PageBuilder\Draft\Exception\InvalidStoredLayoutException;
use Waaseyaa\PageBuilder\Draft\Exception\PageBuilderDraftNotFoundException;
use Waaseyaa\PageBuilder\Draft\Exception\StaleEntityRevisionException;
use Waaseyaa\PageBuilder\Draft\LayoutDraft;
use Waaseyaa\PageBuilder\Editor\Exception\InvalidEditCommandException;
use Waaseyaa\PageBuilder\Editor\Exception\StaleDocumentFingerprintException;
use Waaseyaa\PageBuilder\Preview\RevisionPreviewGrant;
use Waaseyaa\PageBuilder\Revision\Exception\PageBuilderHistoryUnavailableException;
use Waaseyaa\PageBuilder\Surface\Exception\PageBuilderAccessDeniedException;
use Waaseyaa\PageBuilder\Surface\Exception\UnknownPageBuilderSurfaceException;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;
use Waaseyaa\PageBuilder\Wire\EditCommandDecoder;
use Waaseyaa\PageBuilder\Wire\Exception\InvalidWireCommandException;

/** @api */
final readonly class GenericPageBuilderSurfaceHost implements PageBuilderSurfaceHostInterface
{
    private const int MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private PageBuilderSurfaceRegistry $surfaces,
        private EditCommandDecoder $commands = new EditCommandDecoder(),
    ) {}

    public function handleDefinitions(PageBuilderSurfaceRequest $request, string $surface): array
    {
        return $this->execute($request, fn(AuthorizationPrincipalInterface $actor): array => [
            'definitions' => $this->surfaces->get($surface)->definitions($actor),
        ]);
    }

    public function handleDraft(PageBuilderSurfaceRequest $request, string $surface, string $id): array
    {
        return $this->execute($request, fn(AuthorizationPrincipalInterface $actor): array => $this->draft(
            $this->surfaces->get($surface)->draft($actor, $id),
        ));
    }

    public function handleCommand(PageBuilderSurfaceRequest $request, string $surface, string $id): array
    {
        return $this->execute($request, function (AuthorizationPrincipalInterface $actor) use ($request, $surface, $id): array {
            $payload = $this->body($request, [
                'expected_entity_revision_id',
                'expected_document_fingerprint',
                'idempotency_key',
                'command',
            ]);
            $expectedRevision = $this->positiveInt($payload, 'expected_entity_revision_id');
            $fingerprint = $this->string($payload, 'expected_document_fingerprint');
            if (1 !== preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
                throw new InvalidWireCommandException('Document fingerprint must be a lowercase SHA-256 value.');
            }
            $commandPayload = $payload['command'] ?? null;
            if (!is_array($commandPayload) || array_is_list($commandPayload)) {
                throw new InvalidWireCommandException('Command must be an object.');
            }

            return $this->draft($this->surfaces->get($surface)->apply(
                $actor,
                $id,
                $expectedRevision,
                $fingerprint,
                $this->commands->decode($commandPayload),
                $this->string($payload, 'idempotency_key'),
            ));
        });
    }

    public function handlePreview(PageBuilderSurfaceRequest $request, string $surface, string $id): array
    {
        return $this->execute($request, function (AuthorizationPrincipalInterface $actor) use ($request, $surface, $id): array {
            $payload = $this->body($request, ['expected_entity_revision_id']);

            return $this->preview($this->surfaces->get($surface)->preview(
                $actor,
                $id,
                $this->positiveInt($payload, 'expected_entity_revision_id'),
            ));
        });
    }

    public function handleHistory(PageBuilderSurfaceRequest $request, string $surface, string $id): array
    {
        return $this->execute($request, fn(AuthorizationPrincipalInterface $actor): array => [
            'revisions' => $this->surfaces->get($surface)->history($actor, $id),
        ]);
    }

    public function handleRevision(PageBuilderSurfaceRequest $request, string $surface, string $id, string $revision): array
    {
        return $this->execute($request, fn(AuthorizationPrincipalInterface $actor): array => $this->draft(
            $this->surfaces->get($surface)->revision($actor, $id, $this->pathPositiveInt($revision)),
        ));
    }

    public function handleRestore(PageBuilderSurfaceRequest $request, string $surface, string $id): array
    {
        return $this->execute($request, function (AuthorizationPrincipalInterface $actor) use ($request, $surface, $id): array {
            $payload = $this->body($request, [
                'target_revision_id',
                'expected_current_revision_id',
                'idempotency_key',
            ]);

            return $this->draft($this->surfaces->get($surface)->restore(
                $actor,
                $id,
                $this->positiveInt($payload, 'target_revision_id'),
                $this->positiveInt($payload, 'expected_current_revision_id'),
                $this->string($payload, 'idempotency_key'),
            ));
        });
    }

    /** @param \Closure(AuthorizationPrincipalInterface): array<string, mixed> $operation @return array<string, mixed> */
    private function execute(PageBuilderSurfaceRequest $request, \Closure $operation): array
    {
        $actor = $request->actor;
        if (!$actor instanceof AuthorizationPrincipalInterface) {
            return AdminSurfaceResultData::error(401, 'Unauthorized')->toArray();
        }

        try {
            return AdminSurfaceResultData::success($operation($actor))->toArray();
        } catch (UnknownPageBuilderSurfaceException) {
            return AdminSurfaceResultData::error(404, 'Page builder not found')->toArray();
        } catch (PageBuilderDraftNotFoundException) {
            return AdminSurfaceResultData::error(404, 'Page draft not found')->toArray();
        } catch (PageBuilderHistoryUnavailableException) {
            return AdminSurfaceResultData::error(404, 'Page history not available')->toArray();
        } catch (PageBuilderAccessDeniedException) {
            return AdminSurfaceResultData::error(403, 'Page builder access denied')->toArray();
        } catch (StaleEntityRevisionException|StaleDocumentFingerprintException) {
            return AdminSurfaceResultData::error(409, 'Page changed', 'Reload or compare the newer draft before applying this edit.')->toArray();
        } catch (InvalidEditCommandException|InvalidStoredLayoutException) {
            return AdminSurfaceResultData::error(422, 'Page layout is not editable')->toArray();
        } catch (InvalidWireCommandException|\JsonException|\InvalidArgumentException) {
            return AdminSurfaceResultData::error(400, 'Invalid page-builder request')->toArray();
        }
    }

    /** @param list<string> $expected @return array<string, mixed> */
    private function body(PageBuilderSurfaceRequest $request, array $expected): array
    {
        $content = $request->content;
        if ('' === $content || strlen($content) > self::MAX_BODY_BYTES) {
            throw new InvalidWireCommandException('A bounded JSON request body is required.');
        }
        $payload = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidWireCommandException('Request body must be a JSON object.');
        }
        $actual = array_keys($payload);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidWireCommandException('Request fields do not match the page-builder contract.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function positiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidWireCommandException("{$key} must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new InvalidWireCommandException("{$key} must be a non-empty string.");
        }

        return $value;
    }

    private function pathPositiveInt(string $value): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new InvalidWireCommandException('Revision id must be a positive integer.');
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private function draft(LayoutDraft $draft): array
    {
        return [
            'entity_id' => $draft->entityId,
            'entity_revision_id' => $draft->entityRevisionId,
            'document_fingerprint' => $draft->documentFingerprint,
            'document' => $draft->document->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function preview(RevisionPreviewGrant $grant): array
    {
        return [
            'entity_id' => $grant->entityId,
            'revision_id' => $grant->revisionId,
            'expires_at' => $grant->expiresAt,
            'signature' => $grant->signature,
            'preview_url' => $grant->previewUrl,
        ];
    }
}
