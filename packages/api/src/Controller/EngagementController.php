<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Api\Http\JsonApiResponse;
use Waaseyaa\Engagement\CommentPage;
use Waaseyaa\Engagement\EngagementException;
use Waaseyaa\Engagement\EngagementService;
use Waaseyaa\Engagement\RootReference;

/** HTTP input/output mapping; all domain authority remains in EngagementService. */
final readonly class EngagementController
{
    public function __construct(private EngagementService $service) {}

    public function handle(Request $request, AuthorizationPrincipalInterface $principal, string $action): Response
    {
        return match ($action) {
            'summary' => $this->data($this->service->summaries($principal, [$this->root($request)])[0]),
            'like' => $this->like($request, $principal),
            'commentKey' => $this->commentKey($request, $principal),
            'comments' => $this->page($this->service->comments($principal, $this->root($request), ...$this->position($request))),
            'createComment' => $this->createComment($request, $principal),
            'replies' => $this->page($this->service->repliesForComment($principal, $this->uuid($request), ...$this->position($request))),
            'show' => $this->data($this->service->getComment($principal, $this->uuid($request))),
            'edit' => $this->edit($request, $principal),
            'remove' => $this->remove($request, $principal),
            'moderateRemove' => $this->moderateRemove($request, $principal),
            'summaries' => $this->summaries($request, $principal),
            default => throw new \LogicException('Unknown engagement HTTP action.'),
        };
    }

    private function like(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['liked']);
        if (!is_bool($body['liked'] ?? null)) {
            throw $this->invalid();
        }
        return $this->data($this->service->setLiked($principal, $this->root($request), $body['liked']));
    }

    private function commentKey(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['parentUuid']);
        return $this->data(['submissionKey' => $this->service->issueCommentKey($principal, $this->root($request), $this->parent($body))]);
    }

    private function createComment(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['text', 'submissionKey', 'parentUuid']);
        $comment = $this->service->createComment($principal, $this->root($request), $this->string($body, 'text'), $this->string($body, 'submissionKey'), $this->parent($body));
        return $this->data($comment, $comment->replayed ? 200 : 201);
    }

    private function edit(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['text', 'expectedRevision']);
        if (!is_int($body['expectedRevision'] ?? null) || $body['expectedRevision'] < 1) {
            throw $this->invalid();
        }
        return $this->data($this->service->editComment($principal, $this->uuid($request), $this->string($body, 'text'), $body['expectedRevision']));
    }

    private function remove(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        if ($request->getContent() !== '') {
            $this->body($request, []);
        }
        $this->service->removeComment($principal, $this->uuid($request));
        return new Response('', 204, ['Cache-Control' => 'private, no-store']);
    }

    private function moderateRemove(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['reason']);
        $this->service->moderateRemove($principal, $this->uuid($request), $this->string($body, 'reason'));
        return new Response('', 204, ['Cache-Control' => 'private, no-store']);
    }

    private function summaries(Request $request, AuthorizationPrincipalInterface $principal): Response
    {
        $body = $this->body($request, ['roots']);
        if (!is_array($body['roots'] ?? null) || !array_is_list($body['roots']) || count($body['roots']) > 50) {
            throw $this->invalid();
        }
        $roots = [];
        foreach ($body['roots'] as $root) {
            if (!$root instanceof \stdClass) {
                throw $this->invalid();
            }
            $values = get_object_vars($root);
            if (array_diff(array_keys($values), ['type', 'id']) !== []) {
                throw $this->invalid();
            }
            $roots[] = $this->reference($values['type'] ?? null, $values['id'] ?? null);
        }
        return $this->data($this->service->summaries($principal, $roots));
    }

    private function root(Request $request): RootReference
    {
        return $this->reference($request->attributes->get('type'), $request->attributes->get('id'));
    }

    private function reference(mixed $type, mixed $id): RootReference
    {
        if (!is_string($type) || $type === '' || strlen($type) > 128
            || (!is_string($id) && !is_int($id))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            throw $this->invalid();
        }
        return new RootReference($type, (int) $id);
    }

    private function uuid(Request $request): string
    {
        $uuid = $request->attributes->get('uuid');
        if (!is_string($uuid) || $uuid === '' || strlen($uuid) > 128) {
            throw $this->invalid();
        }
        return $uuid;
    }

    /** @return array{?string,int} */
    private function position(Request $request): array
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['cursor', 'limit']) !== []) {
            throw $this->invalid();
        }
        $cursor = $query['cursor'] ?? null;
        $limit = $query['limit'] ?? '20';
        if (($cursor !== null && (!is_string($cursor) || $cursor === ''))
            || !is_string($limit) || !preg_match('/^[1-9][0-9]*$/D', $limit)
            || strlen($limit) > 2 || (int) $limit > 50
        ) {
            throw $this->invalid();
        }
        return [$cursor, (int) $limit];
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function body(Request $request, array $allowed): array
    {
        $contentType = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0]));
        if (!in_array($contentType, ['application/json', 'application/vnd.api+json'], true)) {
            throw $this->invalid();
        }
        try {
            $body = json_decode($request->getContent(), false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->invalid();
        }
        if (!$body instanceof \stdClass) {
            throw $this->invalid();
        }
        $values = get_object_vars($body);
        if (array_diff(array_keys($values), $allowed) !== []) {
            throw $this->invalid();
        }
        return $values;
    }

    /** @param array<string,mixed> $body */
    private function parent(array $body): ?string
    {
        $parent = $body['parentUuid'] ?? null;
        if ($parent !== null && (!is_string($parent) || $parent === '' || strlen($parent) > 128)) {
            throw $this->invalid();
        }
        return $parent;
    }

    /** @param array<string,mixed> $body */
    private function string(array $body, string $key): string
    {
        if (!is_string($body[$key] ?? null)) {
            throw $this->invalid();
        }
        return $body[$key];
    }

    private function page(CommentPage $page): Response
    {
        return $this->data($page->items, meta: ['nextCursor' => $page->nextCursor, 'hasMore' => $page->hasMore]);
    }

    /** @param array<string,mixed> $meta */
    private function data(mixed $data, int $status = 200, array $meta = []): Response
    {
        return new JsonApiResponse(['jsonapi' => ['version' => '1.1'], 'data' => $data, ...($meta === [] ? [] : ['meta' => $meta])], $status, ['Cache-Control' => 'private, no-store']);
    }

    private function invalid(): EngagementException
    {
        return new EngagementException('VALIDATION_FAILED', 422, 'Invalid engagement request.');
    }
}
