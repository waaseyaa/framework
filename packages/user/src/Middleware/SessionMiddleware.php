<?php

declare(strict_types=1);

namespace Waaseyaa\User\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\User\Session\NativeSession;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\User\AnonymousUser;

#[AsMiddleware(pipeline: 'http', priority: 30)]
final class SessionMiddleware implements HttpMiddlewareInterface
{
    private readonly LoggerInterface $logger;

    /**
     * @param EntityStorageInterface $userStorage Storage for loading user entities.
     * @param AccountInterface|null $devFallback Account returned when no session UID exists. Intended for dev environments only.
     */
    public function __construct(
        private readonly EntityStorageInterface $userStorage,
        private readonly ?AccountInterface $devFallback = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        if (session_status() !== \PHP_SESSION_ACTIVE && !$request->attributes->has('_session')) {
            session_start();
        }

        // Attach a session to the Request so controllers can use
        // $request->getSession(). NativeSession reads/writes $_SESSION
        // directly, preserving compatibility with AuthManager.
        if (!$request->hasSession()) {
            $request->setSession(new NativeSession());
        }

        $existingAccount = $request->attributes->get('_account');
        if ($existingAccount instanceof AccountInterface && $existingAccount->isAuthenticated()) {
            return $next->handle($request);
        }

        $account = $this->resolveAccount($request);
        $request->attributes->set('_account', $account);

        return $next->handle($request);
    }

    private function resolveAccount(Request $request): AccountInterface
    {
        $session = $request->attributes->get('_session') ?? ($_SESSION ?? []);
        $uid = $session['waaseyaa_uid'] ?? null;

        if ($uid === null) {
            if ($this->devFallback !== null) {
                $this->logger->info('SessionMiddleware: using dev fallback account (all permissions granted). This should only happen in development.');
                return $this->devFallback;
            }
            return new AnonymousUser();
        }

        try {
            $user = $this->userStorage->load($uid);
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf('SessionMiddleware: failed to load user %s: %s', $uid, $e->getMessage()));
            return new AnonymousUser();
        }

        if ($user instanceof AccountInterface) {
            return $user;
        }

        return new AnonymousUser();
    }
}
