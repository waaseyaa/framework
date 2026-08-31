<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\LocalOperator;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\Process;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorPrincipal;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorToolProfile;
use Waaseyaa\AI\Agent\LocalOperator\LocalOperatorTransportAttestation;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Foundation\Audit\StrictAuditReservation;
use Waaseyaa\User\AnonymousUser;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;

/**
 * ADR-022 D-3.0a and D-6, proven across package boundaries.
 *
 * `waaseyaa/ai-agent` does not depend on `waaseyaa/user` or
 * `waaseyaa/entity-storage`'s user fixtures, so the comparisons against
 * `DevAdminAccount` / `AnonymousUser` and the persistent-account-resolution
 * proof live here, where the whole monorepo is autoloaded.
 *
 * **Acceptance discipline.** Every assertion below runs with the development
 * fallback account explicitly disabled. On this framework an acceptance run
 * with `WAASEYAA_DEV_FALLBACK_ACCOUNT` enabled is invalid: the fallback's
 * blanket `hasPermission()` masks protected field-read denials, so a passing
 * run proves nothing about the access posture.
 */
#[CoversNothing]
final class LocalOperatorTrustBoundaryTest extends TestCase
{
    /** @var list<array{0: string, 1: string|false}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        // Disable the development fallback explicitly, in both the process
        // environment and $_ENV/$_SERVER, for the duration of every test here.
        foreach (['WAASEYAA_DEV_FALLBACK_ACCOUNT', 'APP_ENV', 'APP_DEBUG'] as $variable) {
            $this->savedEnvironment[] = [$variable, getenv($variable)];
        }
        putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as [$variable, $value]) {
            if ($value === false) {
                putenv($variable);
                continue;
            }
            putenv($variable . '=' . $value);
        }
        $this->savedEnvironment = [];
    }

    private function principal(): LocalOperatorPrincipal
    {
        return LocalOperatorPrincipal::forLocalStdioTransport(
            ['environment' => 'local'],
            LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
            null,
            'cli',
        );
    }

    /**
     * The acceptance criterion, stated directly: the development fallback is
     * off, and the principal is not `DevAdminAccount`.
     */
    #[Test]
    public function the_principal_is_not_the_dev_admin_account_and_the_fallback_is_off(): void
    {
        self::assertSame('false', getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT'));

        $principal = $this->principal();

        self::assertNotInstanceOf(DevAdminAccount::class, $principal);
        self::assertNotInstanceOf(AnonymousUser::class, $principal);
        self::assertInstanceOf(AuthorizationPrincipalInterface::class, $principal);
    }

    /**
     * The distinction is demonstrated, not assumed:
     * `DevAdminAccount::ALLOWED_SAPIS` *includes* `cli`, so it is genuinely
     * constructible on a CLI transport — which is exactly why ADR-022 C-2 has
     * to forbid reusing it rather than relying on it being unreachable.
     */
    #[Test]
    public function dev_admin_account_is_constructible_here_and_is_a_wildcard(): void
    {
        self::assertSame('cli', PHP_SAPI, 'the suite runs under the same SAPI the local plane does');

        $devAdmin = new DevAdminAccount();

        self::assertTrue($devAdmin->hasPermission('anything at all'));
        self::assertTrue($devAdmin->hasPermission('bimaaji.mutate'));
        self::assertSame(['administrator'], $devAdmin->getRoles());
        self::assertSame(PHP_INT_MAX, $devAdmin->id());
        self::assertSame('dev-admin', $devAdmin->claimsGeneration());

        $principal = $this->principal();

        self::assertFalse($principal->hasPermission('anything at all'));
        self::assertFalse($principal->hasPermission('bimaaji.mutate'));
        self::assertNotContains('administrator', $principal->getRoles());
        self::assertNotSame($devAdmin->id(), $principal->id());
        self::assertNotSame($devAdmin->claimsGeneration(), $principal->claimsGeneration());
    }

    /** The sentinel is distinct from `AnonymousUser`'s id in identity terms. */
    #[Test]
    public function the_sentinel_is_not_the_anonymous_id(): void
    {
        self::assertSame(0, new AnonymousUser()->id());
        self::assertNotSame(0, $this->principal()->id());
    }

    /**
     * R-3 — persistent account resolution. The string sentinel must not be
     * accepted as a uid lookup key, including through SQLite's type affinity,
     * which coerces a TEXT comparand against an INTEGER column and would
     * otherwise resolve `'local-operator:stdio'` to uid `0`.
     */
    #[Test]
    public function the_sentinel_resolves_to_no_persisted_user(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = EntityType::fromClass(User::class, group: 'people');
        new SqlSchemaHandler($entityType, $database, new FieldDefinitionRegistry())->ensureTable();

        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($database), idKey: $entityType->getKeys()['id']),
            new EventDispatcher(),
            revisionDriver: null,
            database: $database,
        );

        $uid = $repository->save(User::make([
            'name' => 'real-user',
            'mail' => 'real@example.com',
            'email_verified' => true,
            'status' => true,
        ]));
        self::assertGreaterThan(0, $uid, 'a real user must exist, so a null result is not vacuous');

        self::assertNull(
            $repository->find(LocalOperatorPrincipal::ID),
            'The local operator sentinel must not resolve to any persisted account (R-3).',
        );
        self::assertNull($repository->find('0'), 'and must not be reachable through the anonymous uid either');
    }

    /**
     * D-5.D — the principal's contribution to a strict audit reservation.
     * `actorUid` is `null` (never a coerced `0`), the identity travels in
     * metadata, and no machine-identifying value appears in the record.
     */
    #[Test]
    public function it_produces_a_strict_audit_reservation_with_a_null_actor_uid(): void
    {
        $principal = $this->principal();

        $reservation = new StrictAuditReservation(
            correlationId: 'test-correlation',
            // D-5.C's real surface constant belongs to #2659; this is a
            // stand-in so the reservation is well-formed for the assertion.
            surface: 'test.surface',
            operation: 'bimaaji_introspect_graph',
            actorUid: $principal->auditActorUid(),
            safeArguments: [],
            metadata: $principal->auditMetadata(),
        );

        self::assertNull($reservation->actorUid, 'D-5.D.7 — never 0, which would attribute the session to anonymous');
        self::assertSame(LocalOperatorPrincipal::ID, $reservation->metadata['principal']);
        self::assertSame($principal->claimsGeneration(), $reservation->metadata['claims_generation']);

        $encoded = json_encode($reservation->metadata, JSON_THROW_ON_ERROR);
        foreach ([(string) gethostname(), (string) getenv('HOME'), dirname(__DIR__, 3)] as $machineValue) {
            if (strlen($machineValue) < 3) {
                continue;
            }
            self::assertStringNotContainsStringIgnoringCase($machineValue, $encoded);
        }
    }

    /**
     * D-3.0a — **R-6 proven in a production-shaped runtime**, out of process.
     *
     * The ADR is explicit that packaging is the weaker control and that a
     * design depending on the class being *absent* rather than on its
     * *refusing to exist* is one dependency edit away from being wrong. So
     * this spawns a real PHP process, loads the class through the ordinary
     * autoloader (proving it IS reachable), and asserts construction is
     * refused anyway.
     *
     * The process is given the most permissive development-shaped environment
     * available — `APP_ENV=local`, `APP_DEBUG=true`,
     * `WAASEYAA_DEV_FALLBACK_ACCOUNT=true` — with a production-shaped kernel
     * config. That combination is the real hazard: it proves the mutable
     * process environment cannot grant the principal, because
     * `RuntimePolicy::isExplicitDevelopment()` reads only the explicitly
     * configured `environment` key.
     */
    #[Test]
    public function construction_is_refused_in_a_production_shaped_runtime_out_of_process(): void
    {
        $cases = [
            'explicit production config' => ['environment' => 'production'],
            'unconfigured environment (fail closed)' => [],
        ];

        foreach ($cases as $label => $config) {
            $result = $this->runProbe($config, [
                'APP_ENV' => 'local',
                'APP_DEBUG' => 'true',
                'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'true',
            ]);

            self::assertSame('refused', $result['outcome'], sprintf('%s: %s', $label, $result['raw']));
            self::assertSame('R-6', $result['row'], $label);
            self::assertTrue($result['class_was_loadable'], sprintf(
                '%s: the class must be reachable through the autoloader — this test proves it REFUSES, not that it is absent.',
                $label,
            ));
            self::assertNotSame(0, $result['exit_code'], $label);
        }
    }

    /**
     * The paired positive control: the same out-of-process harness, given an
     * explicitly configured development environment, does construct — so the
     * refusals above are the gate working, not the harness failing.
     */
    #[Test]
    public function the_out_of_process_harness_constructs_under_an_explicit_development_config(): void
    {
        $result = $this->runProbe(['environment' => 'local'], [
            // The development fallback stays OFF even on the success path.
            'APP_ENV' => 'production',
            'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'false',
        ]);

        self::assertSame('constructed', $result['outcome'], $result['raw']);
        self::assertSame(LocalOperatorPrincipal::ID, $result['id']);
        self::assertSame(0, $result['exit_code']);
        self::assertFalse($result['is_dev_admin']);
        self::assertSame(['bimaaji.read'], $result['granted']);
        self::assertSame(LocalOperatorToolProfile::DEFAULT_TOOL_IDS, $result['tools']);
    }

    /**
     * Run the probe in a real child process, portably.
     *
     * Deliberately NOT `exec()` with a `NAME=value command` prefix: that is
     * POSIX shell syntax. On native Windows `cmd.exe` does not parse it and
     * tries to execute a program literally named `APP_ENV=local`, so the probe
     * never runs at all — the R-6 proof would fail for the wrong reason, or
     * pass vacuously. `proc_open()` with an **argv array** (no shell parsing on
     * any platform) and an explicit **environment map** works identically on
     * Linux, macOS, and Windows.
     *
     * The child environment is the parent's, plus the overrides. Passing only
     * the overrides would strip `PATH`, and on Windows also `SystemRoot` and
     * `TEMP`, which PHP itself needs in order to start.
     *
     * @param array<string, mixed>  $config
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    private function runProbe(array $config, array $environment): array
    {
        $root = dirname(__DIR__, 3);
        $probe = tempnam(sys_get_temp_dir(), 'local-operator-probe-') . '.php';
        file_put_contents($probe, $this->probeSource($root, $config));

        try {
            [$exitCode, $raw] = self::runPhp([$probe], $environment);
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, 'The probe must emit JSON. Got: ' . $raw);
            $decoded['exit_code'] = $exitCode;
            $decoded['raw'] = $raw;

            return $decoded;
        } finally {
            @unlink($probe);
        }
    }

    /**
     * Portable child-PHP invocation.
     *
     * `symfony/process` rather than `proc_open()`, per #2491's subprocess
     * criterion: a hand-rolled runner that drains stdout to EOF before reading
     * stderr wedges on any child that fills the ~64KB stderr buffer. `Process`
     * multiplexes both streams for us.
     *
     * It also supplies the portability this harness needs. The command is an
     * **argv array**, so no shell parses it on any platform, and `$env` entries
     * are **merged over the inherited environment** by the component rather
     * than prefixed onto a command string. The original form —
     * `exec("NAME=value php probe.php")` — is POSIX shell syntax: `cmd.exe`
     * does not parse it and tries to run a program named `APP_ENV=local`, so on
     * Windows the probe never reached PHP and the R-6 proof passed vacuously.
     *
     * @param list<string>          $arguments   Arguments after the PHP binary.
     * @param array<string, string> $environment Overrides merged over the parent environment.
     * @return array{0: int, 1: string} exit code, combined output
     */
    public static function runPhp(array $arguments, array $environment = []): array
    {
        $process = new Process([PHP_BINARY, ...$arguments], null, $environment);
        $process->setTimeout(60.0);
        $process->run();

        return [
            $process->getExitCode() ?? -1,
            trim($process->getOutput() . $process->getErrorOutput()),
        ];
    }

    /** @param array<string, mixed> $config */
    private function probeSource(string $root, array $config): string
    {
        $autoload = var_export($root . '/vendor/autoload.php', true);
        $encodedConfig = var_export($config, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            require {$autoload};

            use Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorPrincipal;
            use Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorRefusal;
            use Waaseyaa\\AI\\Agent\\LocalOperator\\LocalOperatorTransportAttestation;
            use Waaseyaa\\User\\DevAdminAccount;

            \$report = [
                'outcome' => 'unknown',
                'row' => null,
                'message' => null,
                'id' => null,
                'granted' => null,
                'tools' => null,
                'is_dev_admin' => null,
                // Proves the refusal is a runtime refusal, not an absent class.
                'class_was_loadable' => class_exists(LocalOperatorPrincipal::class),
                'sapi' => PHP_SAPI,
            ];

            try {
                \$principal = LocalOperatorPrincipal::forLocalStdioTransport(
                    {$encodedConfig},
                    LocalOperatorTransportAttestation::STDIO_TRANSPORT_ID,
                );
                \$report['outcome'] = 'constructed';
                \$report['id'] = \$principal->id();
                \$report['granted'] = \$principal->toolProfile()->capabilities();
                \$report['tools'] = \$principal->toolProfile()->toolIds();
                \$report['is_dev_admin'] = \$principal instanceof DevAdminAccount;
                echo json_encode(\$report, JSON_THROW_ON_ERROR);
                exit(0);
            } catch (LocalOperatorRefusal \$refusal) {
                \$report['outcome'] = 'refused';
                \$report['row'] = \$refusal->row;
                \$report['message'] = \$refusal->getMessage();
                echo json_encode(\$report, JSON_THROW_ON_ERROR);
                exit(3);
            } catch (Throwable \$error) {
                \$report['outcome'] = 'error';
                \$report['message'] = \$error::class . ': ' . \$error->getMessage();
                echo json_encode(\$report, JSON_THROW_ON_ERROR);
                exit(4);
            }
            PHP;
    }
}
