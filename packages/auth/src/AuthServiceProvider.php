<?php

declare(strict_types=1);

namespace Waaseyaa\Auth;

use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Auth\Config\AuthConfig;
use Waaseyaa\Auth\Rekey\AuthTokenHmacRekeyAdapter;
use Waaseyaa\Auth\Security\AuthTokenSecret;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasMiddlewareInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider implements HasMiddlewareInterface, ProvidesApplicationMasterRekeyContributionsInterface
{
    public function register(): void
    {
        $this->singleton(AuthManager::class, fn() => new AuthManager(
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));

        $this->singleton(AtomicRateLimiterInterface::class, function () {
            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            return new DatabaseRateLimiter($db);
        });
        $this->singleton(RateLimiterInterface::class, fn() => $this->resolve(AtomicRateLimiterInterface::class));

        $authConfig = $this->config['auth'] ?? [];
        $appEnv = $this->config['app_env'] ?? ($_ENV['APP_ENV'] ?? 'production');

        $this->singleton(Config\AuthConfig::class, fn() => Config\AuthConfig::fromArray($authConfig, $appEnv));

        $this->singleton(Token\AuthTokenRepositoryInterface::class, function () use ($authConfig) {
            $applicationSecret = $this->resolveOptional(ApplicationSecret::class);
            $secret = AuthTokenSecret::resolve(
                $authConfig['token_secret'] ?? null,
                $applicationSecret instanceof ApplicationSecret ? $applicationSecret : null,
                $this->resolveRuntimeEnvironment(),
            );

            $db = $this->resolve(\Waaseyaa\Database\DatabaseInterface::class);
            $repo = new Token\AuthTokenRepository($db, $secret);
            $repo->ensureSchema();
            return $repo;
        });

        // Durable bearer-token lifecycle store (#2177 F3). Consumed by the MCP
        // write tier's default auth and the `bearer-token:*` operator commands.
        // Deliberately NOT verifying schema here: first use performs a
        // read-only migration-owned schema check. Runtime code never installs
        // or repairs the bearer-token table.
        $this->singleton(Token\Bearer\BearerTokenStoreInterface::class, fn() => new Token\Bearer\DatabaseBearerTokenStore(
            $this->resolve(\Waaseyaa\Database\DatabaseInterface::class),
        ));

        $this->singleton(TwoFactorManager::class, fn() => new TwoFactorManager());

        $this->singleton(TwoFactorService::class, fn() => new TwoFactorService(
            $this->resolve(TwoFactorManager::class),
            $this->resolve(EntityTypeManager::class),
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));
    }

    /**
     * @return list<HttpMiddlewareInterface>
     */
    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [];
    }

    /**
     * Contribute to application-master rotation ONLY when the auth-token HMAC key is
     * actually derived from that master.
     *
     * With a valid explicit `AUTH_TOKEN_SECRET` the signing key is independent, so
     * rotating the application master cannot invalidate a single outstanding token.
     * Contributing the drain adapter regardless made those independently signed tokens
     * block a rotation they are unaffected by: an operator with a perfectly valid
     * independent secret could not rotate `WAASEYAA_APP_SECRET` until every outstanding
     * reset, verify, and invite token had expired, for no security reason at all.
     *
     * The classification is {@see AuthTokenSecret::usesDerivedCustody()}'s, the same
     * authority the token binding in {@see self::register()} uses, so the two cannot
     * disagree about which mode is active.
     *
     * Two orderings matter here and are deliberate. Classification runs FIRST, so an
     * invalid explicit secret throws instead of being read as absent and silently
     * contributing. The database authority is required only AFTER the derived branch is
     * taken, so an application in explicit mode is not forced to stand up a database
     * authority for a contribution it does not make.
     */
    public function applicationMasterRekeyContributions(): iterable
    {
        if (!AuthTokenSecret::usesDerivedCustody(
            ($this->config['auth'] ?? [])['token_secret'] ?? null,
            $this->resolveRuntimeEnvironment(),
        )) {
            return;
        }

        $database = $this->resolveOptional(\Waaseyaa\Database\DatabaseInterface::class);
        if (!$database instanceof \Waaseyaa\Database\DatabaseInterface) {
            throw new \LogicException('Auth-token HMAC rekey composition requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new AuthTokenHmacRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                ApplicationSecret::PURPOSE_AUTH_TOKEN_HMAC,
                'waaseyaa/auth',
                ApplicationMasterPurposeStrategy::DrainOrExpire,
                AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
                AuthConfig::LONGEST_TOKEN_TTL_SECONDS,
                AuthTokenHmacRekeyAdapter::ID,
                'expire-outstanding-tokens',
            )],
        );
    }

    /**
     * Resolve the runtime environment the same way {@see \Waaseyaa\Foundation\Kernel\AbstractKernel}
     * does: the canonical source is the config `environment` key (what the kernel and the
     * integration test harness set), with `app_env` / `APP_ENV` as fallbacks. Reading only
     * `app_env` here would misclassify a `local` kernel as production and break boot.
     */
    private function resolveRuntimeEnvironment(): string
    {
        $env = $this->config['environment'] ?? $this->config['app_env'] ?? null;
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $fromEnv = getenv('APP_ENV');

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : 'production';
    }
}
