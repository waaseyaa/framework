<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\DatabaseRateLimiter;
use Waaseyaa\Auth\RateLimiterInterface;
use Waaseyaa\Auth\Tests\Support\AuthSchema;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Tests\Support\AuthenticationEligibilityFixture;
use Waaseyaa\Tests\Support\UserIdentityLookupFixture;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\User;

/**
 * Audit evidence for #2985 (rate-limiting lane). These are CHARACTERIZATION
 * tests: they pin the behaviour observed at the audited commit, they are not
 * an endorsement of it. `admits_more_than_five_verification_attempts_...`
 * asserts the defect and must be inverted by the repair for #2992.
 * `a_success_on_one_account_clears_failures_recorded_against_another` likewise
 * asserts the defect tracked by #2993 — it is sequential, so closing #2992
 * alone will not change it. `sequential_requests_are_refused_at_the_limit`
 * encodes #763's acceptance criterion and must keep passing throughout.
 *
 * `LoginController` gates on a pure read — `tooManyAttempts()` at
 * LoginController.php:43, which is `attempts() >= $max` — and only writes the
 * counter afterwards, on its two failure branches (`hit()` at :88 and :96),
 * clearing it on success (:104). Between the read and the write sit the JSON
 * decode, the identity lookup, and `password_verify()`, which is deliberately
 * slow. Concurrent requests therefore all observe the same pre-increment count
 * and all pass a gate that should have admitted only some of them.
 *
 * Concurrency is modelled deterministically rather than with real threads:
 * {@see DeferredWriteRateLimiter} forwards every read to the real
 * {@see DatabaseRateLimiter} but holds `hit()` writes until `flush()`. That is
 * exactly the state of N in-flight requests whose gate reads have completed and
 * whose counter writes have not yet committed — a realizable interleaving, not
 * a synthetic one. The reads are served by the production limiter against real
 * SQLite, and the post-flush assertions confirm every deferred write did land.
 */
#[CoversClass(LoginController::class)]
final class LoginRateLimitInterleavingTest extends TestCase
{
    private const LIMIT = 5;

    private const IP = '203.0.113.7';

    private const BUCKET = 'login:' . self::IP;

    private DBALDatabase $database;

    private DatabaseRateLimiter $limiter;

    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        AuthSchema::install($this->database);
        $this->limiter = new DatabaseRateLimiter($this->database);
        $this->entityTypeManager = $this->rosterWith([
            ['uid' => 1, 'name' => 'victim', 'password' => 'the victim password'],
            ['uid' => 2, 'name' => 'attacker', 'password' => 'the attacker password'],
        ]);
    }

    /**
     * The headline finding. The bucket is seeded to one below the limit, so the
     * intended policy permits exactly ONE more failed attempt in this window.
     * Eight concurrent requests are admitted instead, and every one of them
     * reaches credential verification.
     *
     * A 401 is only reachable at LoginController.php:92, after
     * `findActiveByLogin()` and `password_verify()` have both run; a request
     * stopped by the gate returns 429 at :47 without touching either. Counting
     * 401s therefore counts requests that reached credential verification.
     */
    #[Test]
    public function admits_more_than_five_verification_attempts_in_one_window(): void
    {
        for ($i = 0; $i < self::LIMIT - 1; $i++) {
            $this->limiter->hit(self::BUCKET, 60);
        }
        self::assertSame(4, $this->limiter->attempts(self::BUCKET));
        self::assertFalse($this->limiter->tooManyAttempts(self::BUCKET, self::LIMIT));

        $inFlight = new DeferredWriteRateLimiter($this->limiter);
        $controller = $this->controller($inFlight);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $controller($this->request('victim', 'wrong password'))->getStatusCode();
        }

        $reachedVerification = \count(array_filter($statuses, static fn(int $s): bool => $s === 401));
        $refusedAtGate = \count(array_filter($statuses, static fn(int $s): bool => $s === 429));

        self::assertSame(0, $refusedAtGate, 'The gate refused nothing: every read saw the same count of 4.');
        self::assertSame(8, $reachedVerification, 'All eight requests ran password_verify().');
        self::assertGreaterThan(
            self::LIMIT,
            $reachedVerification,
            'More than five requests reached credential verification inside one window.',
        );

        // Every deferred write lands, so the limiter itself is not undercounting.
        // The bucket overshoots to 12 for a configured limit of 5.
        $inFlight->flush();
        self::assertSame(12, $this->limiter->attempts(self::BUCKET));
        self::assertTrue($this->limiter->tooManyAttempts(self::BUCKET, self::LIMIT));
    }

    /**
     * Sequentially the gate holds exactly. This is the control: the overshoot
     * above is produced by the check-then-write ordering, not by a defect in
     * DatabaseRateLimiter, whose own increment is atomic.
     */
    #[Test]
    public function sequential_requests_are_refused_at_the_limit(): void
    {
        $controller = $this->controller($this->limiter);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $controller($this->request('victim', 'wrong password'))->getStatusCode();
        }

        self::assertSame([401, 401, 401, 401, 401, 429, 429, 429], $statuses);
    }

    /**
     * Policy characterization: the five counts FAILED attempts, not admitted
     * ones. `hit()` is reached only from the two failure branches, so a correct
     * credential never increments the bucket — it clears it at :104.
     *
     * The response is a 500 because a unit test has no active PHP session
     * (LoginController.php:106); the clear at :104 has already run by then,
     * which is what this asserts.
     */
    #[Test]
    public function only_failed_attempts_are_counted_and_success_clears_the_bucket(): void
    {
        $controller = $this->controller($this->limiter);

        $controller($this->request('victim', 'wrong password'));
        self::assertSame(1, $this->limiter->attempts(self::BUCKET), 'A failure increments.');

        $response = $controller($this->request('victim', 'the victim password'));
        self::assertSame(500, $response->getStatusCode(), 'Stopped at the session guard, past the clear.');
        self::assertSame(0, $this->limiter->attempts(self::BUCKET), 'A success clears the whole bucket.');
    }

    /**
     * Why closing the race alone is not sufficient. The bucket is keyed by IP
     * only (`login:<ip>`, LoginController.php:41), and success clears the whole
     * row (`DatabaseRateLimiter::clear()` is a DELETE). So one valid credential
     * resets the failure count accumulated against a DIFFERENT account on the
     * same IP.
     *
     * This is sequential — no interleaving, no race. Making the gate atomic
     * does not change it: an attacker holding any one valid account on the
     * shared address can zero the counter between bursts indefinitely. Tracked
     * separately from the concurrency defect as #2993 for that reason.
     */
    #[Test]
    public function a_success_on_one_account_clears_failures_recorded_against_another(): void
    {
        $controller = $this->controller($this->limiter);

        for ($i = 0; $i < self::LIMIT - 1; $i++) {
            self::assertSame(401, $controller($this->request('victim', 'wrong password'))->getStatusCode());
        }
        self::assertSame(4, $this->limiter->attempts(self::BUCKET));

        $controller($this->request('attacker', 'the attacker password'));
        self::assertSame(0, $this->limiter->attempts(self::BUCKET), 'The victim bucket was reset by an unrelated login.');

        // The budget against the victim is replenished in full.
        self::assertSame(401, $controller($this->request('victim', 'wrong password'))->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Fixture wiring
    // ------------------------------------------------------------------

    private function controller(RateLimiterInterface $rateLimiter): LoginController
    {
        return new LoginController(
            entityTypeManager: $this->entityTypeManager,
            rateLimiter: $rateLimiter,
            twoFactor: new TwoFactorService(
                new TwoFactorManager(),
                $this->entityTypeManager,
                new UserInternalFieldReaderFixture(),
            ),
            identityLookup: new UserIdentityLookupFixture(),
            internalFields: new UserInternalFieldReaderFixture(),
            eligibility: AuthenticationEligibilityFixture::policy(),
        );
    }

    private function request(string $username, string $password): Request
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            [],
            [],
            [],
            ['REMOTE_ADDR' => self::IP],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }

    /**
     * @param list<array{uid: int, name: string, password: string}> $accounts
     */
    private function rosterWith(array $accounts): EntityTypeManager
    {
        $userType = new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        new SqlSchemaHandler($userType, $this->database)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $userType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database), 'uid'),
            new EventDispatcher(),
            database: $this->database,
        );
        $manager = new EntityTypeManager(
            new EventDispatcher(),
            repositoryFactory: static fn(): EntityRepository => $repository,
        );
        $manager->registerEntityType($userType);

        foreach ($accounts as $account) {
            $user = new User([
                'uid' => $account['uid'],
                'uuid' => 'u-' . $account['uid'],
                'name' => $account['name'],
                'mail' => $account['name'] . '@example.test',
                'status' => true,
                // Cost 4 keeps the eight-request interleaving fast; the
                // controller reads the cost from the hash itself.
                'pass' => password_hash($account['password'], PASSWORD_BCRYPT, ['cost' => 4]),
                'two_factor_secret' => null,
                'two_factor_recovery_codes_hash' => null,
            ]);
            $user->enforceIsNew();
            $repository->save($user);
        }

        return $manager;
    }
}

/**
 * Models requests that are in flight together: gate reads are served by the
 * real limiter, counter writes are held until {@see flush()}. `clear()` is
 * forwarded immediately because the success path commits it before returning.
 */
final class DeferredWriteRateLimiter implements RateLimiterInterface
{
    /** @var list<array{string, int}> */
    private array $pending = [];

    public function __construct(private readonly RateLimiterInterface $inner) {}

    public function hit(string $key, int $decaySeconds): void
    {
        $this->pending[] = [$key, $decaySeconds];
    }

    public function flush(): void
    {
        foreach ($this->pending as [$key, $decaySeconds]) {
            $this->inner->hit($key, $decaySeconds);
        }
        $this->pending = [];
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return $this->inner->tooManyAttempts($key, $maxAttempts);
    }

    public function attempts(string $key): int
    {
        return $this->inner->attempts($key);
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return $this->inner->remaining($key, $maxAttempts);
    }

    public function clear(string $key): void
    {
        $this->inner->clear($key);
    }
}
