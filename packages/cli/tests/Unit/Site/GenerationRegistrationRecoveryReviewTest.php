<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Exception\SiteInitializationCollisionException;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\ArtifactPlan;
use Waaseyaa\SiteContract\Generation\ComposerProviderRegistration;
use Waaseyaa\SiteContract\Generation\GeneratedSite;
use Waaseyaa\SiteContract\Generation\GenerationUnitDisposition;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteInitializationService::class)]
final class GenerationRegistrationRecoveryReviewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_registration_recovery_review_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        new SiteInitializationService($this->root)->initialize($this->site());
        file_put_contents($this->root . '/composer.json', "{}\n");
        chmod($this->root . '/composer.json', 0o600);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    #[Test]
    #[DataProvider('changedComposerTuples')]
    public function changedComposerTupleRefusesWithoutMutation(string $state, string $bytes, int $mode): void
    {
        $interrupted = $this->interruptComposerMerge();
        $targetBytes = $bytes === 'prior' ? "{}\n" : $interrupted['installed'];
        file_put_contents($this->root . '/composer.json', $targetBytes);
        chmod($this->root . '/composer.json', $mode);
        $this->setComposerItemState($state);

        $thrown = null;
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
        } catch (\Throwable $error) {
            $thrown = $error;
        }

        self::assertInstanceOf(SiteInitializationCollisionException::class, $thrown);
        clearstatcache();
        self::assertSame($targetBytes, file_get_contents($this->root . '/composer.json'));
        self::assertSame($mode, fileperms($this->root . '/composer.json') & 0o777);
        self::assertFileExists($interrupted['journal']);
        self::assertFileExists($interrupted['backup']);
    }

    public static function changedComposerTuples(): iterable
    {
        yield 'prior bytes with foreign mode' => ['installing', 'prior', 0o644];
        yield 'installed bytes with foreign mode' => ['installing', 'installed', 0o644];
        yield 'pending marker after replacement' => ['pending', 'installed', 0o600];
    }

    #[Test]
    #[DataProvider('exactComposerRetryStates')]
    public function exactComposerRetryStatesRemainRecoverable(string $state, string $bytes): void
    {
        $interrupted = $this->interruptComposerMerge();
        if ($bytes === 'prior') {
            file_put_contents($this->root . '/composer.json', "{}\n");
            chmod($this->root . '/composer.json', 0o600);
        }
        $this->setComposerItemState($state);

        self::assertTrue($this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true));
        clearstatcache();
        self::assertSame("{}\n", file_get_contents($this->root . '/composer.json'));
        self::assertSame(0o600, fileperms($this->root . '/composer.json') & 0o777);
        self::assertFileDoesNotExist($interrupted['journal']);
    }

    public static function exactComposerRetryStates(): iterable
    {
        yield 'pending prior tuple' => ['pending', 'prior'];
        yield 'installing prior tuple' => ['installing', 'prior'];
        yield 'applied prior tuple' => ['applied', 'prior'];
        yield 'installing installed tuple' => ['installing', 'installed'];
        yield 'applied installed tuple' => ['applied', 'installed'];
    }

    #[Test]
    #[DataProvider('substitutedBackups')]
    public function pendingMergeCannotDiscardSubstitutedBackup(string $substitution): void
    {
        $interrupted = $this->interruptComposerMerge();
        file_put_contents($this->root . '/composer.json', "{}\n");
        chmod($this->root . '/composer.json', 0o600);
        $this->setComposerItemState('pending');
        if ($substitution === 'bytes') {
            file_put_contents($interrupted['backup'], "foreign backup\n");
        } else {
            chmod($interrupted['backup'], 0o644);
        }
        $backupBytes = file_get_contents($interrupted['backup']);
        clearstatcache();
        $backupMode = fileperms($interrupted['backup']) & 0o777;
        try {
            $this->invoke(new SiteInitializationService($this->root), 'recoverIfRequired', true);
            self::fail('Expected substituted backup refusal.');
        } catch (SiteInitializationCollisionException $exception) {
            self::assertStringContainsString('backup was substituted', $exception->getMessage());
        }
        self::assertSame("{}\n", file_get_contents($this->root . '/composer.json'));
        self::assertFileExists($interrupted['journal']);
        self::assertSame($backupBytes, file_get_contents($interrupted['backup']));
        clearstatcache();
        self::assertSame($backupMode, fileperms($interrupted['backup']) & 0o777);
    }

    public static function substitutedBackups(): iterable
    {
        yield 'different bytes' => ['bytes'];
        yield 'different mode' => ['mode'];
    }

    /** @return array{installed: string, journal: string, backup: string} */
    private function interruptComposerMerge(): array
    {
        $service = new SiteInitializationService($this->root, static function (string $stage, int $index, string $path): void {
            if ($stage === 'after-replace' && $path === 'composer.json') {
                throw new \Error('interrupted Composer merge');
            }
        });
        try {
            $this->publish($service, $this->plan());
            self::fail('Expected interruption.');
        } catch (\Error $error) {
            self::assertSame('interrupted Composer merge', $error->getMessage());
        }
        $journal = $this->journal();
        foreach ($journal['items'] as $item) {
            if (($item['kind'] ?? null) === 'composer-merge') {
                return [
                    'installed' => (string) file_get_contents($this->root . '/composer.json'),
                    'journal' => $this->root . '/.waaseyaa/site-init.transaction.json',
                    'backup' => $this->root . '/' . $item['backup'],
                ];
            }
        }
        self::fail('Composer merge item was not journaled.');
    }

    private function setComposerItemState(string $state): void
    {
        $journal = $this->journal();
        foreach ($journal['items'] as &$item) {
            if (($item['kind'] ?? null) === 'composer-merge') {
                $item['state'] = $state;
            }
        }
        unset($item);
        file_put_contents(
            $this->root . '/.waaseyaa/site-init.transaction.json',
            json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    /** @return array<string, mixed> */
    private function journal(): array
    {
        return json_decode(
            (string) file_get_contents($this->root . '/.waaseyaa/site-init.transaction.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function plan(): ArtifactPlan
    {
        return new ArtifactPlan(
            'ExampleCompiler',
            1,
            'scaffold:example',
            GenerationUnitDisposition::Managed,
            str_repeat('a', 64),
            [],
            registrations: [new ComposerProviderRegistration('App\\A')],
        );
    }

    private function publish(SiteInitializationService $service, ArtifactPlan $plan): void
    {
        $lock = fopen($this->root . '/.waaseyaa/site-init.lock', 'c+b');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $prepared = $this->invoke($service, 'prepareUnitPlan', $plan);
            $this->invoke($service, 'publish', $prepared['prepared'], $prepared['retirements'], $prepared['composerMerge']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function invoke(SiteInitializationService $service, string $method, mixed ...$arguments): mixed
    {
        return new \ReflectionMethod($service, $method)->invoke($service, ...$arguments);
    }

    private function site(): GeneratedSite
    {
        $manifest = <<<'YAML'
            schema: waaseyaa.site
            version: 1
            generator_version: 1
            application:
              id: example
              name: Example
              canonical_origin: {config_key: APP_ORIGIN}
            framework:
              revision_policy: exact-lock
              observed_lock_sha256: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
            content_types:
              - {id: page, canonical_route: '/{slug}'}
            capabilities:
              - id: publishing
                state: active
                package: waaseyaa/publishing
                provider: site.publishing
                configuration_authority: .waaseyaa/site.yaml#/capabilities/publishing
                public_routes: []
                data_classification: public
                lifecycle: [create, publish]
                verification: [tests/Acceptance/SiteGoldenPathTest.php]
            personal_data_stores: []
            recipes: []
            verification: {command: bin/maintenance/site-verify}
            YAML;

        return new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($manifest));
    }
}
