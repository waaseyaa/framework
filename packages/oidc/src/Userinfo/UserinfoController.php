<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Userinfo;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\Oidc\Token\IdTokenMinter;
use Waaseyaa\User\User;

/**
 * GET/POST /oidc/userinfo — OIDC Core §5.3.
 *
 * Authenticates the bearer access token (JWT), loads the subject User entity,
 * and builds the claim set. Each claim is gated through FieldAccessPolicyInterface
 * (DIR-004): claims backed by a Forbidden field are omitted entirely — never
 * serialised as null or "".
 *
 * Response is bare application/json (NOT application/vnd.api+json).
 *
 * @api
 */
final readonly class UserinfoController
{
    public function __construct(
        private IdTokenMinter $idTokenMinter,
        private AccessTokenIssuer $accessTokenIssuer,
        private EntityTypeManager $entityTypeManager,
        private EntityAccessHandler $entityAccessHandler,
        private UserinfoClaimResolver $claimResolver,
        private string $issuer,
    ) {}

    public function __invoke(Request $request): Response
    {
        if (!in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return $this->error(405, 'Bearer realm="oidc"', 'invalid_request', 'GET or POST required.');
        }

        // Extract bearer token
        $authHeader = $request->headers->get('Authorization');
        if (!is_string($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->error(401, 'Bearer realm="oidc"', 'invalid_token', 'Missing or malformed Authorization header.');
        }

        $jwt = substr($authHeader, 7);
        if ($jwt === '') {
            return $this->error(401, 'Bearer realm="oidc"', 'invalid_token', 'Empty bearer token.');
        }

        // Verify JWT signature + standard claims
        $claims = $this->idTokenMinter->verifyAndDecode($jwt, $this->issuer, $this->issuer);
        if ($claims === null) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Token validation failed.');
        }

        // Check oidc_access_token revocation
        $jti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : null;
        if ($jti !== null) {
            $storedToken = $this->accessTokenIssuer->findByJti($jti);
            if ($storedToken === null || ($storedToken['revoked_at'] ?? null) !== null) {
                return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Token has been revoked.');
            }
        }

        $accountId = isset($claims['sub']) && is_string($claims['sub']) ? $claims['sub'] : null;
        if ($accountId === null) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Missing sub claim.');
        }

        // Load the User entity
        $storage = $this->entityTypeManager->getStorage('user');
        if (!$storage instanceof SqlEntityStorage) {
            return $this->serverError('User storage unavailable.');
        }

        $user = $storage->load((int) $accountId);
        if (!$user instanceof User) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Subject not found.');
        }

        // Resolve scopes from JWT
        $scope = isset($claims['scope']) && is_string($claims['scope']) ? $claims['scope'] : 'openid';
        $scopes = array_filter(explode(' ', $scope), static fn(string $s): bool => $s !== '');
        $candidateClaims = $this->claimResolver->claimsFor(array_values($scopes));

        // Build response — always include sub, gate others through field-access
        $response = ['sub' => (string) $user->id()];

        foreach ($candidateClaims as $claim) {
            if ($claim === 'sub') {
                continue; // already set
            }

            $fieldName = $this->claimResolver->fieldNameForClaim($claim);
            if ($fieldName === null) {
                // No backing field (e.g. address, phone_number) — omit
                continue;
            }

            // DIR-004: check field access using the token subject as the requesting account
            if (!$this->isFieldAccessible($user, $fieldName)) {
                // Forbidden — omit entirely, never emit null or ""
                continue;
            }

            $value = $user->get($fieldName);
            if ($value !== null) {
                $response[$claim] = $value;
            }
        }

        $jsonResponse = new JsonResponse($response, 200);
        $jsonResponse->headers->set('Content-Type', 'application/json');
        $jsonResponse->headers->set('Cache-Control', 'no-store');

        return $jsonResponse;
    }

    /**
     * Check whether a field on the User entity is accessible.
     * Open-by-default: Neutral = accessible, only Forbidden restricts.
     */
    private function isFieldAccessible(User $user, string $fieldName): bool
    {
        $result = $this->entityAccessHandler->checkFieldAccess($user, $fieldName, 'view', $user);

        return !$result->isForbidden();
    }

    private function error(int $status, string $wwwAuthenticate, string $error, string $description): Response
    {
        $response = new JsonResponse([
            'error' => $error,
            'error_description' => $description,
        ], $status);
        $response->headers->set('WWW-Authenticate', $wwwAuthenticate);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function serverError(string $detail): Response
    {
        return new JsonResponse(['error' => 'server_error', 'error_description' => $detail], 500);
    }
}
