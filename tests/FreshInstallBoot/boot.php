<?php

declare(strict_types=1);

/**
 * Fresh-install boot probe (#2426).
 *
 * Runs inside a disposable consumer that installed the full framework from
 * path repositories at the exact candidate tree, and that has already completed
 * the governed installation phase (`waaseyaa install:init`). It asserts the
 * kernel then reaches a usable state through ordinary runtime boot alone.
 *
 * The defect this guards: access-policy discovery runs inside
 * AbstractKernel::boot(). If any policy's dependency graph demands state that
 * only exists after configuration is activated, a brand-new install can never
 * boot, because activating configuration itself requires a booted kernel.
 * `Skeleton Smoke (Packaged-form CI)` proves the same property against the
 * published artifact, but only AFTER the tag exists. This probe proves it from
 * source, so a release can be gated on it before anything is published.
 *
 * The probe deliberately goes past boot to a real entity round-trip. Booting is
 * necessary but not sufficient: v0.1.0-alpha.296 booted a fresh install
 * correctly and still could not save, because the PRE_SAVE listener
 * WorkflowStateGuard resolves workflow configuration, which reads the active
 * generation. An earlier revision of this probe stopped at boot, so the release
 * gate went green over exactly that failure while Skeleton Smoke — which does
 * save — went red. Whatever a consumer must do on day one, this proves.
 *
 * Exit 0 = a fresh install booted AND completed a write; exit 1 = it did not.
 */

require __DIR__ . '/vendor/autoload.php';

use App\Provider\AuthExtensionConsumerServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Auth\Extension\RegisteredUserReference;
use Waaseyaa\Auth\Extension\RegistrationContext;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\User;

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    // The governed installation phase has already run (the harness invoked
    // `waaseyaa install:init`, exactly as a real deployment does). This probe
    // proves what a consumer gets AFTER it: an ordinary runtime boot and a
    // day-one write, with no privileged setup of its own.
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);

    $entityTypes = $kernel->getEntityTypeManager();
    if (!$entityTypes->hasDefinition('user')) {
        fwrite(STDERR, "::error::fresh-install boot registered no 'user' entity type\n");
        exit(1);
    }

    fwrite(STDOUT, "fresh-install kernel boot OK\n");

    // Day-one write. This is the step that catches a fresh install which boots
    // but cannot persist anything — the PRE_SAVE path resolves workflow
    // configuration, so it exercises configuration reads that boot alone does
    // not reach.
    $marker = 'fresh-install-' . bin2hex(random_bytes(4));
    $repository = $entityTypes->getRepository('user');
    $uid = $repository->save(User::make([
        'name' => $marker,
        'mail' => $marker . '@example.test',
        'permissions' => ['access user profiles'],
        'status' => 1,
        'created' => time(),
    ]));

    if ($uid <= 0) {
        fwrite(STDERR, "::error::fresh-install save produced no uid (got {$uid})\n");
        exit(1);
    }

    $reloaded = $repository->find((string) $uid);
    if (!$reloaded instanceof User) {
        fwrite(STDERR, '::error::fresh-install reload returned ' . get_debug_type($reloaded) . ", not User\n");
        exit(1);
    }

    fwrite(STDOUT, "fresh-install entity round-trip OK (uid={$uid})\n");

    // #2437: the same exact candidate installed as a downstream package can
    // enable every narrow extension while Framework credential/session code
    // remains authoritative. The application provider owns policy only.
    $resolver = $kernel->getHttpServiceResolver();
    $extensions = $resolver->resolve(AuthExtensionRegistry::class);
    if (!$extensions instanceof AuthExtensionRegistry || count($extensions->owners()) !== 5) {
        throw new RuntimeException('Packaged consumer did not compose all five auth extension slots.');
    }
    $decision = $extensions->registration(new RegistrationContext('Consumer', 'consumer@example.test', 'open'));
    if (!$decision->allowed || !$decision->requiresApproval) {
        throw new RuntimeException('Packaged registration approval policy was not applied.');
    }
    $profile = $extensions->validateProfile(['community' => 'North']);
    $reference = new RegisteredUserReference((string) $uid, $marker, true);
    $extensions->storeProfile($reference, $profile);
    if (AuthExtensionConsumerServiceProvider::$storedProfile !== ['user_id' => (string) $uid, 'community' => 'North']) {
        throw new RuntimeException('Application-owned profile did not persist by Framework user id.');
    }
    $presentation = $extensions->mail('password_reset', (string) $uid);
    if ($presentation?->subject !== 'Consumer password_reset'
        || $extensions->redirect('login', (string) $uid)->path !== '/consumer-login') {
        throw new RuntimeException('Packaged mail/redirect policies were not applied.');
    }
    $extensions->dispatch('registered', (string) $uid, ['approval_required' => true]);
    if (AuthExtensionConsumerServiceProvider::$lifecycleEvents !== 1) {
        throw new RuntimeException('Packaged lifecycle listener did not receive the typed auth event.');
    }

    $loginUser = User::make([
        'name' => 'packaged-login',
        'mail' => 'packaged-login@example.test',
        'status' => 1,
        'created' => time(),
    ]);
    $loginUser->setRawPassword('correct horse battery staple');
    $repository->save($loginUser);
    $loginUid = $loginUser->id();
    if ($loginUid === null) {
        throw new RuntimeException('Packaged login user did not receive a persisted id.');
    }
    $rateLimiter = $resolver->resolve(RateLimiterInterface::class);
    $twoFactor = $resolver->resolve(TwoFactorService::class);
    $identityLookup = $resolver->resolve(UserIdentityLookupInterface::class);
    $internalFields = $resolver->resolve(UserInternalFieldReaderInterface::class);
    if (!$rateLimiter instanceof RateLimiterInterface || !$twoFactor instanceof TwoFactorService
        || !$identityLookup instanceof UserIdentityLookupInterface
        || !$internalFields instanceof UserInternalFieldReaderInterface) {
        throw new RuntimeException('Packaged auth dependencies were not resolvable.');
    }
    $login = new LoginController($entityTypes, $rateLimiter, $twoFactor, $identityLookup, $internalFields, $extensions);
    $wrong = Request::create('/auth/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.77'], content: json_encode([
        'username' => 'packaged-login',
        'password' => 'wrong password',
    ], JSON_THROW_ON_ERROR));
    if ($login($wrong)->getStatusCode() !== 401 || isset($_SESSION['waaseyaa_uid'])) {
        throw new RuntimeException('Consumer policy bypassed Framework credential verification.');
    }
    $correct = Request::create('/auth/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.77'], content: json_encode([
        'username' => 'packaged-login',
        'password' => 'correct horse battery staple',
    ], JSON_THROW_ON_ERROR));
    $loginResponse = $login($correct);
    $loginPayload = json_decode((string) $loginResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
    if ($loginResponse->getStatusCode() !== 200
        || (string) ($_SESSION['waaseyaa_uid'] ?? '') !== (string) $loginUid
        || ($loginPayload['meta']['redirect'] ?? null) !== '/consumer-login') {
        throw new RuntimeException(sprintf(
            'Framework login/session core did not remain effective with every extension enabled (status=%d, session_uid=%s, saved_uid=%s, redirect=%s, payload=%s).',
            $loginResponse->getStatusCode(),
            var_export($_SESSION['waaseyaa_uid'] ?? null, true),
            var_export($loginUid, true),
            var_export($loginPayload['meta']['redirect'] ?? null, true),
            json_encode($loginPayload, JSON_THROW_ON_ERROR),
        ));
    }

    fwrite(STDOUT, "fresh-install auth extension consumer OK\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::fresh-install kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
