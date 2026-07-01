<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\SqlEntityStorage;
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
 * @api
 */
final class OidcClientController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * GET /api/oidc-clients — list all OIDC clients.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $storage = $this->storage();
        // C-22 WP2: the query builder now lives on the repository (accessCheck(false): system context).
        $ids = $this->repository()->getQuery()->accessCheck(false)->execute();
        $clients = array_filter(
            array_map(fn(mixed $id): ?OidcClient => $storage->load($id) instanceof OidcClient ? $storage->load($id) : null, $ids),
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

        return new JsonResponse(['data' => $this->serialize($client)]);
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

        $storage = $this->storage();
        $client = new OidcClient();
        $this->hydrateFromBody($client, $body);

        [$plainSecret, $secretHash] = $this->generateSecret();
        $client->setClientSecretHash($secretHash);

        $storage->save($client);

        $data = $this->serialize($client);
        $data['client_secret'] = $plainSecret; // shown once only

        return new JsonResponse(['data' => $data], 201);
    }

    /**
     * PATCH /api/oidc-clients/{id} — update a client.
     */
    public function update(string $id, Request $request): Response
    {
        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        $body = $this->parseBody($request);
        if ($body === null) {
            return $this->badRequest('Invalid JSON body.');
        }

        $this->hydrateFromBody($client, $body);
        $this->storage()->save($client);

        return new JsonResponse(['data' => $this->serialize($client)]);
    }

    /**
     * DELETE /api/oidc-clients/{id} — delete a client.
     */
    public function delete(string $id): Response
    {
        $client = $this->loadOrFail($id);
        if ($client === null) {
            return $this->notFound($id);
        }

        $this->storage()->delete([$client]);

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
        $this->storage()->save($client);

        $data = $this->serialize($client);
        $data['client_secret'] = $plainSecret;

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(OidcClient $client): array
    {
        return [
            'id' => (string) $client->id(),
            'client_id' => $client->getClientId(),
            'name' => $client->getName(),
            'redirect_uris' => $client->getRedirectUris(),
            'scopes' => $client->getScopes(),
            'grant_types' => $client->getGrantTypes(),
            'is_confidential' => $client->isConfidential(),
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
        $entity = $this->storage()->load((int) $id);

        return $entity instanceof OidcClient ? $entity : null;
    }

    private function storage(): SqlEntityStorage
    {
        $storage = $this->entityTypeManager->getStorage('oidc_client');
        if (!$storage instanceof SqlEntityStorage) {
            throw new \RuntimeException('OidcClient storage must be SqlEntityStorage.');
        }

        return $storage;
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
