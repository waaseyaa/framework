<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Consent;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Oidc\Authorize\AuthorizeController;
use Waaseyaa\Oidc\Repository\AuthorizationCodeRepositoryInterface;
use Waaseyaa\Oidc\Userinfo\UserinfoClaimResolver;

/**
 * GET/POST /oidc/consent — consent screen for OIDC authorization.
 *
 * GET: render the consent screen (Twig template).
 * POST: process Approve / Deny from the consent form.
 *
 * The pending authorization request is stored in session under
 * AuthorizeController::SESSION_KEY by AuthorizeController.
 *
 * @api
 */
final readonly class ConsentScreenController
{
    public function __construct(
        private ConsentRepository $consentRepository,
        private UserinfoClaimResolver $claimResolver,
        private AuthorizationCodeRepositoryInterface $codeRepository,
        private string $loginPath = '/login',
    ) {}

    public function __invoke(Request $request): Response
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface || !$account->isAuthenticated()) {
            return new RedirectResponse(
                $this->loginPath . '?return_to=' . urlencode($request->getRequestUri()),
                302,
            );
        }

        if (!$request->hasSession()) {
            return $this->renderError('No session available. Please return to the application and try again.');
        }

        $pending = $request->getSession()->get(AuthorizeController::SESSION_KEY);
        if (!is_array($pending)) {
            return $this->renderError('No pending authorization request found. Please return to the application and try again.');
        }

        if ($request->getMethod() === 'POST') {
            return $this->handleSubmit($request, $account, $pending);
        }

        return $this->renderConsentScreen($pending);
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function handleSubmit(Request $request, AccountInterface $account, array $pending): Response
    {
        $action = $request->request->get('action');
        $redirectUri = (string) ($pending['redirect_uri'] ?? '');
        $state = isset($pending['state']) && is_string($pending['state']) ? $pending['state'] : null;

        if ($action !== 'approve') {
            // Deny — redirect with error
            $request->getSession()->remove(AuthorizeController::SESSION_KEY);
            $params = ['error' => 'access_denied', 'error_description' => 'user_denied_consent'];
            if ($state !== null) {
                $params['state'] = $state;
            }

            return new RedirectResponse($this->appendQuery($redirectUri, $params), 302);
        }

        // Approve — record consent and issue code
        $clientId = (string) ($pending['client_id'] ?? '');
        $scopes = is_array($pending['scopes']) ? array_values(array_map('strval', $pending['scopes'])) : [];
        $accountId = (string) $account->id();

        $this->consentRepository->record($accountId, $clientId, $scopes);
        $request->getSession()->remove(AuthorizeController::SESSION_KEY);

        $code = $this->codeRepository->issue(
            clientId: $clientId,
            account: $account,
            redirectUri: $redirectUri,
            scopes: $scopes,
            codeChallenge: (string) ($pending['code_challenge'] ?? ''),
            codeChallengeMethod: (string) ($pending['code_challenge_method'] ?? 'S256'),
            nonce: isset($pending['nonce']) && is_string($pending['nonce']) ? $pending['nonce'] : null,
        );

        $params = ['code' => $code->code];
        if ($state !== null) {
            $params['state'] = $state;
        }

        return new RedirectResponse($this->appendQuery($redirectUri, $params), 302);
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function renderConsentScreen(array $pending): Response
    {
        $clientId = htmlspecialchars((string) ($pending['client_id'] ?? ''), \ENT_QUOTES);
        $scopes = is_array($pending['scopes']) ? $pending['scopes'] : [];

        $scopeItems = '';
        foreach ($scopes as $scope) {
            $scopeItems .= '<li>' . htmlspecialchars(
                $this->claimResolver->scopeDescriptionFor((string) $scope),
                \ENT_QUOTES,
            ) . '</li>';
        }

        $body = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><title>Authorize Application</title></head>
            <body>
            <h1>Authorize {$clientId}</h1>
            <p>This application is requesting access to:</p>
            <ul>{$scopeItems}</ul>
            <form method="POST" action="/oidc/consent">
                <button type="submit" name="action" value="approve">Approve</button>
                <button type="submit" name="action" value="deny">Deny</button>
            </form>
            </body>
            </html>
            HTML;

        return new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function renderError(string $message): Response
    {
        $escaped = htmlspecialchars($message, \ENT_QUOTES);
        $body = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><title>Error</title></head>
            <body><h1>Error</h1><p>{$escaped}</p></body>
            </html>
            HTML;

        return new Response($body, 400, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }
}
