<?php
declare(strict_types=1);
require '/home/fsd42/dev/waaseyaa-worktrees/fw-2985-session-audit/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Auth\Authentication\VerifiedEmailAuthenticationEligibility;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\Session\AuthenticatedSession;
use Waaseyaa\User\User;

/** A DISABLED user whose session_generation is UNCHANGED (7). */
function disabledUser(): User {
    $u = new User(['uid' => 42, 'name' => 'blocked-user', 'mail' => 'b@example.test',
                   'status' => 0, 'session_generation' => 7, 'roles' => ['authenticated']]);
    // NOT enforceIsNew(): this represents a PERSISTED, already-saved user.
    return $u;
}

/** Reader reporting the persisted truth: active=false, generation=7. */
function reader(): UserInternalFieldReaderInterface {
    return new class implements UserInternalFieldReaderInterface {
        public function credentials(\Waaseyaa\Entity\EntityInterface $user): \Waaseyaa\Access\User\UserCredentialSnapshot { throw new \LogicException('unused'); }
        public function twoFactor(\Waaseyaa\Entity\EntityInterface $user): \Waaseyaa\Access\User\UserTwoFactorSnapshot { throw new \LogicException('unused'); }
        public function mailDelivery(\Waaseyaa\Entity\EntityInterface $user): \Waaseyaa\Access\User\UserMailSnapshot { throw new \LogicException('unused'); }
        public function maintenanceAuthorization(\Waaseyaa\Entity\EntityInterface $user): \Waaseyaa\Access\User\UserAuthorizationSnapshot { throw new \LogicException('unused'); }
        public function verification(\Waaseyaa\Entity\EntityInterface $user): UserVerificationSnapshot {
            return new UserVerificationSnapshot('b@example.test', true, false); // active = FALSE
        }
        public function sessionIdentity(\Waaseyaa\Entity\EntityInterface $user): UserSessionSnapshot {
            return new UserSessionSnapshot('blocked-user', 'b@example.test', ['authenticated'], 7);
        }
    };
}

function repo(): \Waaseyaa\Entity\Repository\EntityRepositoryInterface {
    // Real first-party test helper, backed by a minimal storage double.
    $storage = new class implements \Waaseyaa\Entity\Storage\EntityStorageInterface {
        public function load(int|string $id): ?\Waaseyaa\Entity\EntityInterface { return disabledUser(); }
        public function loadMultiple(array $ids = []): array { return [disabledUser()]; }
        public function create(array $values = []): \Waaseyaa\Entity\EntityInterface { return disabledUser(); }
        public function save(\Waaseyaa\Entity\EntityInterface $entity): int { return 42; }
        public function delete(array $entities): void {}
        public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface { throw new \LogicException('unused'); }
        public function loadByKey(string $key, mixed $value): ?\Waaseyaa\Entity\EntityInterface { return disabledUser(); }
        public function getEntityTypeId(): string { return 'user'; }
    };
    return new \Waaseyaa\Entity\Testing\StorageBackedStubRepository($storage);
}

function run(string $label, ?object $eligibility): void {
    $mw = new SessionMiddleware(
        userRepository: repo(),
        internalFields: reader(),
        authenticationEligibility: $eligibility,
    );
    $request = Request::create('/api/whoami', 'GET');
    // A VALID session for uid 42 at the CURRENT generation (7) — nothing stale.
    $request->attributes->set('_session', [
        AuthenticatedSession::USER_ID_KEY => 42,
        AuthenticatedSession::GENERATION_KEY => 7,
    ]);

    $handler = new class implements \Waaseyaa\Foundation\Middleware\HttpHandlerInterface {
        public ?object $seen = null;
        public function handle(Request $r): Response { $this->seen = $r->attributes->get('_account'); return new Response('ok'); }
    };
    $mw->process($request, $handler);
    $acct = $handler->seen;

    // Route-level consequence, using the REAL AccessChecker against a route
    // that requires authentication.
    $route = new \Symfony\Component\Routing\Route('/api/private');
    $route->setOption('_authenticated', true);
    $verdict = 'n/a';
    if ($acct instanceof \Waaseyaa\Access\AccountInterface) {
        $r = new \Waaseyaa\Access\AccessChecker()->check($route, $acct);
        $verdict = $r->isAllowed() ? 'ALLOWED' : ($r->isUnauthenticated() ? 'unauthenticated(401)' : ($r->isForbidden() ? 'forbidden(403)' : 'neutral'));
    }
    printf("%-34s => %-28s | authenticated=%-5s | id=%-3s | _authenticated route: %s\n", $label,
        $acct === null ? 'NULL' : (new \ReflectionClass($acct))->getShortName(),
        $acct instanceof \Waaseyaa\Access\AccountInterface ? var_export($acct->isAuthenticated(), true) : 'n/a',
        $acct instanceof \Waaseyaa\Access\AccountInterface ? var_export($acct->id(), true) : 'n/a',
        $verdict);
}

echo "Disabled user (status=0), session_generation UNCHANGED at 7, valid session for uid 42\n\n";
run('A. no waaseyaa/auth (null policy)', null);
$cfg = \Waaseyaa\Auth\Config\AuthConfig::fromArray(['require_verified_email' => false], 'production');
run('B. waaseyaa/auth installed', new VerifiedEmailAuthenticationEligibility($cfg, reader()));
