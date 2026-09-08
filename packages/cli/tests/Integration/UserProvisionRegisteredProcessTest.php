<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\SiteContract\CanonicalJson;

/** Fresh processes prove physical identity fencing and persisted Community Events roles. */
#[CoversNothing]
final class UserProvisionRegisteredProcessTest extends TestCase
{
    private const PASSWORD = 'process-sentinel-private-password';
    private string $root;
    private string $runner;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa-user-provision-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700, true));
        $this->runner = dirname(__DIR__) . '/Fixtures/UserProvisionRegistered/process-runner.php';
        $prepared = $this->process('prepare');
        self::assertSame(0, $prepared->run(), $prepared->getErrorOutput());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    public function concurrentRetryAndFreshProcessInspectionPreserveRegisteredRolesWithoutSecretEvidence(): void
    {
        $contributor = $this->request('community-owner', 'owner@example.test', 'contributor');
        $first = $this->process('provision', $contributor);
        $second = $this->process('provision', $contributor);
        self::assertStringNotContainsString(self::PASSWORD, $first->getCommandLine());
        self::assertStringNotContainsString(self::PASSWORD, $second->getCommandLine());

        $first->start();
        $second->start();
        $firstExit = $first->wait();
        $secondExit = $second->wait();
        $results = [
            json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR),
            json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR),
        ];
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertContains($statuses, [
            ['created', 'existing'],
            ['created', 'uncertain'],
        ], $first->getErrorOutput() . $second->getErrorOutput() . $first->getOutput() . $second->getOutput());
        self::assertSame(0, min($firstExit, $secondExit));
        self::assertContains(max($firstExit, $secondExit), [0, 2]);

        if (in_array('uncertain', $statuses, true)) {
            $retry = $this->process('provision', $contributor);
            self::assertSame(0, $retry->run(), $retry->getErrorOutput());
            self::assertSame('existing', json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);
            self::assertStringNotContainsString(self::PASSWORD, $retry->getCommandLine() . $retry->getOutput() . $retry->getErrorOutput());
        }

        $reviewer = $this->process(
            'provision',
            $this->request('community-reviewer', 'reviewer@example.test', 'reviewer'),
        );
        self::assertSame(0, $reviewer->run(), $reviewer->getErrorOutput());
        self::assertSame('created', json_decode($reviewer->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status']);

        $inspection = $this->process('inspect');
        self::assertSame(0, $inspection->run(), $inspection->getErrorOutput());
        self::assertSame([
            'community-owner' => [
                'permissions' => ['create events', 'edit own events'],
                'roles' => ['contributor'],
            ],
            'community-reviewer' => [
                'permissions' => ['review events', 'publish events'],
                'roles' => ['reviewer'],
            ],
        ], json_decode($inspection->getOutput(), true, flags: JSON_THROW_ON_ERROR));

        foreach ([$first, $second, $reviewer, $inspection] as $process) {
            self::assertSame('', $process->getErrorOutput());
            self::assertStringNotContainsString(self::PASSWORD, $process->getOutput());
            self::assertStringNotContainsString(self::PASSWORD, $process->getErrorOutput());
        }
    }

    #[Test]
    public function inactiveHistoricalLoginRefusesBeforeASecondAccountIsCreated(): void
    {
        $seed = $this->process('seed-inactive');
        self::assertSame(0, $seed->run(), $seed->getErrorOutput());

        $provision = $this->process(
            'provision',
            $this->request('historical-owner', 'new-owner@example.test', 'contributor'),
        );
        self::assertSame(1, $provision->run(), $provision->getErrorOutput());
        self::assertSame([
            'account_id' => null,
            'code' => 'identity_conflict',
            'role' => 'contributor',
            'schema' => 'waaseyaa.user-provision-registered-result.v1',
            'status' => 'refused',
            'version' => 1,
        ], json_decode($provision->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $provision->getErrorOutput());
        self::assertStringNotContainsString(self::PASSWORD, $provision->getCommandLine() . $provision->getOutput());
    }

    #[Test]
    public function ordinaryRepositoryCreationAndSafeProvisioningCannotCreateCaseVariantDuplicates(): void
    {
        $request = $this->request('contended.owner', 'contended@example.test', 'contributor');
        $provision = $this->process('provision', $request);
        $ordinary = $this->process('ordinary-create');
        $provision->start();
        $ordinary->start();
        $provision->wait();
        $ordinary->wait();

        $provisionResult = json_decode($provision->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $ordinaryResult = json_decode($ordinary->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains($provisionResult['status'], ['created', 'refused', 'uncertain']);
        self::assertContains($ordinaryResult['status'], ['created', 'conflict', 'uncertain']);
        self::assertContains('created', [$provisionResult['status'], $ordinaryResult['status']]);

        $inspection = $this->process('inspect-contended');
        self::assertSame(0, $inspection->run(), $inspection->getErrorOutput());
        $stored = json_decode($inspection->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $stored['count']);
        self::assertCount(1, $stored['accounts']);
        $safeWon = $stored['accounts'][0]['name'] === 'contended.owner';
        self::assertSame(
            $safeWon ? ['contributor'] : [],
            $stored['accounts'][0]['roles'],
        );
        self::assertSame(
            $safeWon ? ['create events', 'edit own events'] : [],
            $stored['accounts'][0]['permissions'],
        );

        $retry = $this->process('provision', $request);
        $retryExit = $retry->run();
        $retryResult = json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($safeWon ? 0 : 1, $retryExit, $retry->getErrorOutput());
        self::assertSame($safeWon ? 'existing' : 'refused', $retryResult['status']);

        foreach ([$provision, $ordinary, $inspection, $retry] as $process) {
            self::assertSame('', $process->getErrorOutput());
            self::assertStringNotContainsString(self::PASSWORD, $process->getCommandLine() . $process->getOutput());
        }
    }

    private function process(string $mode, ?string $input = null): Process
    {
        return new Process(
            [PHP_BINARY, $this->runner, $mode, $this->root . '/accounts.sqlite'],
            dirname(__DIR__, 3),
            ['APP_ENV' => 'testing', 'PROVISION_PUBLIC_MARKER' => 'bounded-test'],
            input: $input,
            timeout: 30,
        );
    }

    private function request(string $username, string $mail, string $role): string
    {
        return CanonicalJson::encode([
            'email' => $mail,
            'password' => self::PASSWORD,
            'role' => $role,
            'schema' => UserProvisionRegisteredHandler::INPUT_SCHEMA,
            'username' => $username,
            'version' => 1,
        ]) . "\n";
    }
}
