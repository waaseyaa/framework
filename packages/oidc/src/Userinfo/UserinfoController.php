<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Userinfo;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Oidc\Token\AccessTokenIssuer;
use Waaseyaa\User\User;

/**
 * GET/POST /oidc/userinfo — OIDC Core §5.3.
 *
 * Authenticates the bearer access token (opaque — looked up in the persisted
 * oidc_access_token row, with revocation and expiry enforced), loads the subject
 * User entity, and builds the claim set. Each claim is gated through FieldAccessPolicyInterface
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
        private AccessTokenIssuer $accessTokenIssuer,
        private EntityTypeManager $entityTypeManager,
        private EntityAccessHandler $entityAccessHandler,
        private AccountPrincipalFactoryInterface $principalFactory,
        private UserinfoClaimResolver $claimResolver,
        private UserInternalFieldReaderInterface $userInternalFields,
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

        $bearer = substr($authHeader, 7);
        if ($bearer === '') {
            return $this->error(401, 'Bearer realm="oidc"', 'invalid_token', 'Empty bearer token.');
        }

        // Access tokens are opaque (not JWTs): authenticate by looking up the
        // persisted oidc_access_token row, then enforce revocation and expiry.
        $token = $this->accessTokenIssuer->findByOpaqueToken($bearer);
        if ($token === null) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Unknown access token.');
        }

        if (($token['revoked_at'] ?? null) !== null) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Token has been revoked.');
        }

        if (!isset($token['expires_at']) || (int) $token['expires_at'] < time()) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Token has expired.');
        }

        $accountId = isset($token['account_id']) && is_scalar($token['account_id'])
            ? (string) $token['account_id']
            : null;
        if ($accountId === null || $accountId === '') {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Token has no subject.');
        }

        // Load the User entity (C-22 WP3: canonical repository).
        $user = $this->entityTypeManager->getRepository('user')->find($accountId);
        if (!$user instanceof User) {
            return $this->error(401, 'Bearer realm="oidc", error="invalid_token"', 'invalid_token', 'Subject not found.');
        }

        // Resolve scopes from the persisted token row
        $scope = isset($token['scope']) && is_string($token['scope']) && $token['scope'] !== '' ? $token['scope'] : 'openid';
        $scopes = array_filter(explode(' ', $scope), static fn(string $s): bool => $s !== '');
        $candidateClaims = $this->claimResolver->claimsFor(array_values($scopes));
        $principal = $this->principalFactory->fromAccount($user);
        $profileAccessible = $this->entityAccessHandler->check($user, 'view', $principal)->isAllowed();

        // Build response — always include sub, gate others through field-access
        $response = ['sub' => (string) $user->id()];

        foreach ($candidateClaims as $claim) {
            if ($claim === 'sub') {
                continue; // already set
            }
            if (!$profileAccessible && in_array($claim, ['name', 'preferred_username', 'updated_at'], true)) {
                continue;
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

            $value = match ($fieldName) {
                'mail' => $this->userInternalFields->verification($user)->mail,
                'email_verified' => $this->userInternalFields->verification($user)->emailVerified,
                'name' => $this->userInternalFields->sessionIdentity($user)->name,
                // Internal fields require a purpose-specific audited reader;
                // unsupported claims are omitted rather than read generically.
                default => null,
            };
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
}
