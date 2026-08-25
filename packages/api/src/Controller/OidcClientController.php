<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Api\JsonApiDocument;
use Waaseyaa\Api\JsonApiError;
use Waaseyaa\Entity\Concurrency\EntityMutationConflictException;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * Admin-only CRUD controller for OIDC client registration (WP05).
 *
 * Exposes: index, show, create, update, delete, regenerateSecret.
 *
 * Access control: enforced by `_role: admin` route option in BuiltinRouteRegistrar.
 * NFR-001 — do NOT re-check the role here.
 *
 * client_secret handling:
 * - create + regenerateSecret: generates a 32-byte URL-safe secret, returns
 *   it ONCE in the response, stores only its password_hash().
 * - index + show: secret field is ABSENT from the response (not null, not [hidden]).
 *
 * Existing-entity PATCH/DELETE require the same strong If-Match fence as
 * JsonApiRouter: absence is 428, malformed/weak/list/wildcard is 400, and a
 * valid but stale token is 412. Tokens are derived per request from current
 * entity state and are never retained on this controller.
 *
 * @api
 */
final class OidcClientController
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly OidcClientSystemReader $systemReader = new OidcClientSystemReader(),
    ) {}

    /**
     * GET /api/oidc-clients — list all OIDC clients.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the
        // repository (accessCheck(false): system context).
        $repository = $this->repository();
        $ids = $repository->getQuery()->accessCheck(false)->execute();
        $clients = array_filter(
            $repository->findMany($ids),
            static fn(mixed $c): bool => $c instanceof OidcClient,
        );

        return [
            'data' => array_values(array_map(fn(OidcClient $c): array => $this->serialize($c), $clients)),
        ];
    }

    /**
     * GET /api/oidc-clients/{id} — show one client.
     */
    public function show(string $id): Response
    {
        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        return $this->clientResponse($client);
    }

    /**
     * POST /api/oidc-clients — create a client.
     */
    public function create(Request $request): Response
    {
        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->badRequest('Invalid JSON body.');
        }

        $client = new OidcClient();
        $this->hydrateFromBody($client, $body);

        [$plainSecret, $secretHash] = $this->generateSecret();
        $client->setClientSecretHash($secretHash);

        $this->repository()->save($client);

        $data = $this->serialize($client);
        $data['client_secret'] = $plainSecret; // shown once only

        return new JsonResponse(['data' => $data], 201);
    }

    /**
     * PATCH /api/oidc-clients/{id} — update a client.
     */
    public function update(string $id, Request $request): Response
    {
        $expectation = $this->requireMutationExpectation($request);
        if ($expectation instanceof Response) {
            return $expectation;
        }

        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        $conflict = $this->applyMutationExpectation($client, $expectation);
        if ($conflict !== null) {
            return $conflict;
        }

        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->badRequest('Invalid JSON body.');
        }

        $this->hydrateFromBody($client, $body);
        try {
            $this->repository()->save($client);
        } catch (EntityMutationConflictException) {
            return $this->mutationConflict();
        }

        return $this->clientResponse($client);
    }

    /**
     * DELETE /api/oidc-clients/{id} — delete a client.
     */
    public function delete(string $id, Request $request): Response
    {
        $expectation = $this->requireMutationExpectation($request);
        if ($expectation instanceof Response) {
            return $expectation;
        }

        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        $conflict = $this->applyMutationExpectation($client, $expectation);
        if ($conflict !== null) {
            return $conflict;
        }

        try {
            $this->repository()->delete($client);
        } catch (EntityMutationConflictException) {
            return $this->mutationConflict();
        }

        return new Response('', 204);
    }

    /**
     * POST /api/oidc-clients/{id}/regenerate-secret — generate a new client secret.
     *
     * Returns the new secret ONCE; subsequent reads do not expose it.
     */
    public function regenerateSecret(string $id): Response
    {
        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        [$plainSecret, $secretHash] = $this->generateSecret();
        $client->setClientSecretHash($secretHash);
        $this->repository()->save($client);

        $data = $this->serialize($client);
        $data['client_secret'] = $plainSecret;

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OidcClient $client): array
    {
        $registration = $this->systemReader->registration($client);

        return [
            'id' => (string) $client->id(),
            'client_id' => $client->getClientId(),
            'name' => $registration->name,
            'redirect_uris' => $registration->redirectUris,
            'scopes' => $registration->scopes,
            'grant_types' => $registration->grantTypes,
            'is_confidential' => $registration->confidential,
            // client_secret intentionally absent from index/show
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function hydrateFromBody(OidcClient $client, array $body): void
    {
        if (isset($body['name']) && is_string($body['name'])) {
            $client->setName($body['name']);
        }

        if (isset($body['client_id']) && is_string($body['client_id'])) {
            $client->setClientId($body['client_id']);
        }

        if (isset($body['redirect_uris']) && is_array($body['redirect_uris'])) {
            $client->setRedirectUris(array_values(array_filter($body['redirect_uris'], 'is_string')));
        }

        if (isset($body['scopes']) && is_array($body['scopes'])) {
            $client->setScopes(array_values(array_filter($body['scopes'], 'is_string')));
        }

        if (isset($body['grant_types']) && is_array($body['grant_types'])) {
            $client->setGrantTypes(array_values(array_filter($body['grant_types'], 'is_string')));
        }

        if (isset($body['is_confidential']) && is_bool($body['is_confidential'])) {
            $client->setConfidential($body['is_confidential']);
        }
    }

    private function loadOrFail(string $id): ?OidcClient
    {
        $entity = $this->repository()->find($id);

        return $entity instanceof OidcClient ? $entity : null;
    }

    private function repository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('oidc_client');
    }

    /** @return array{0: string, 1: string} [plain, hash] */
    private function generateSecret(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = password_hash($plain, PASSWORD_DEFAULT);

        return [$plain, $hash];
    }

    /** @return array<string, mixed>|null */
    private function parseBody(Request $request): ?array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function requireMutationExpectation(Request $request): EntityMutationToken|Response
    {
        $ifMatch = $request->headers->get('If-Match');
        if ($ifMatch === null || trim($ifMatch) === '') {
            return $this->jsonApiResponse(428, JsonApiDocument::fromErrors([
                new JsonApiError(
                    status: '428',
                    title: 'Precondition Required',
                    detail: 'Existing-entity mutation requires exactly one strong If-Match value from the loaded resource.',
                    code: 'MUTATION_PRECONDITION_REQUIRED',
                ),
            ], statusCode: 428)->toArray());
        }

        try {
            return EntityMutationToken::fromHttpIfMatch(trim($ifMatch));
        } catch (\InvalidArgumentException $exception) {
            return $this->jsonApiResponse(400, JsonApiDocument::fromErrors([
                new JsonApiError(
                    status: '400',
                    title: 'Bad Request',
                    detail: $exception->getMessage(),
                    code: 'INVALID_MUTATION_PRECONDITION',
                ),
            ], statusCode: 400)->toArray());
        }
    }

    private function applyMutationExpectation(OidcClient $client, EntityMutationToken $expected): ?Response
    {
        $current = $client->mutationToken();
        if ($expected->entityTypeId !== 'oidc_client'
            || $expected->entityId !== (string) $client->id()
            || $current === null
            || !hash_equals($current->toOpaqueString(), $expected->toOpaqueString())
        ) {
            return $this->mutationConflict();
        }
        $client->_hydrateMutationToken($expected);

        return null;
    }

    private function mutationConflict(): Response
    {
        return $this->jsonApiResponse(412, JsonApiDocument::fromErrors([
            new JsonApiError(
                status: '412',
                title: 'Precondition Failed',
                detail: 'The resource changed after the supplied mutation precondition was observed.',
                code: 'MUTATION_PRECONDITION_FAILED',
            ),
        ], statusCode: 412)->toArray());
    }

    private function clientResponse(OidcClient $client, int $status = 200): JsonResponse
    {
        $payload = ['data' => $this->serialize($client)];
        $headers = [];
        $token = $client->mutationToken();
        if ($token !== null) {
            $headers['ETag'] = $token->toStrongEtag();
            $payload['meta'] = ['mutation_token' => $token->toOpaqueString()];
        }

        return new JsonResponse($payload, $status, $headers);
    }

    private function notFound(string $id): Response
    {
        return new JsonResponse([
            'errors' => [['status' => '404', 'title' => 'Not Found', 'detail' => "OIDC client {$id} not found."]],
        ], 404);
    }

    private function badRequest(string $detail): Response
    {
        return new JsonResponse([
            'errors' => [['status' => '400', 'title' => 'Bad Request', 'detail' => $detail]],
        ], 400);
    }
}
