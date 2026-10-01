<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Management;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\ProjectSourceDiscovery;
use Waaseyaa\CLI\Site\Management\ManagementInputDiscovery;
use PHPUnit\Framework\Attributes\DataProvider;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Generation\SiteArtifactRenderer;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\SiteContract\Tests\Fixtures\Management\ManagementFixture as F;

final class SiteDoctorManagementTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void { new Filesystem()->remove($this->roots); }

    private function fixture(): string
    {
        $root = sys_get_temp_dir() . '/waaseyaa_management_' . bin2hex(random_bytes(8));
        mkdir($root, 0700); $this->roots[] = $root;
        file_put_contents($root . '/composer.lock', "{}\n");
        $digest = hash_file('sha256', $root . '/composer.lock');
        $yaml = <<<YAML
        schema: waaseyaa.site
        version: 1
        generator_version: 1
        application: {id: test-site, name: Test, canonical_origin: {config_key: APP_ORIGIN}}
        framework: {revision_policy: exact-lock, observed_lock_sha256: $digest}
        content_types: [{id: page, canonical_route: '/{slug}'}]
        capabilities:
          - id: forms
            state: active
            package: waaseyaa/publishing
            provider: site.publishing
            configuration_authority: .waaseyaa/site.yaml#/capabilities/forms
            public_routes: []
            data_classification: public
            lifecycle: [create, publish]
            verification: [tests/Acceptance/SiteGoldenPathTest.php]
        personal_data_stores: []
        recipes: []
        verification: {command: bin/maintenance/site-verify}
        YAML;
        new SiteInitializationService($root)->initialize(new SiteArtifactRenderer()->render(new SiteManifestParser()->parse($yaml)));
        file_put_contents($root . '/composer.json', '{"extra":{"waaseyaa":{"providers":[]}}}');
        return $root;
    }

    public function testLegacySiteWithoutCompanionPreservesDoctorBehavior(): void
    {
        $report = new SiteDoctorService()->inspect($this->fixture());
        self::assertTrue($report->passed);
        self::assertArrayNotHasKey('management_sha256', $report->toArray());
    }

    public function testCompanionIsInspectedAndCannotPassWithoutLiveInventory(): void
    {
        $root = $this->fixture(); $path = $root . '/.waaseyaa/management.json';
        $json = json_encode(F::document(), JSON_THROW_ON_ERROR); file_put_contents($path, $json);
        $before = file_get_contents($root . '/.waaseyaa/generated.json');
        $report = new SiteDoctorService()->inspect($root);
        self::assertFalse($report->passed);
        self::assertSame(['SITE035_MANAGEMENT_INVENTORY_UNVERIFIED'], array_column($report->findings, 'id'));
        self::assertSame(hash('sha256', $json), $report->toArray()['management_sha256']);
        self::assertSame($json, file_get_contents($path));
        self::assertSame($before, file_get_contents($root . '/.waaseyaa/generated.json'));
    }

    public function testCurrentAdapterEvidencePassesAndSourceChangesInvalidateIt(): void
    {
        $root = $this->fixture(); file_put_contents($root . '/.waaseyaa/management.json', json_encode(F::document(), JSON_THROW_ON_ERROR));
        $op = new ManagementManifestParser()->operation(F::operation());
        $site = new SiteManifestParser()->parse(file_get_contents($root . '/.waaseyaa/site.yaml'));
        $inventory = F::inventory([$op], null, new ManagementInputDiscovery()->digest($root), $site->digest);
        self::assertTrue(new SiteDoctorService($inventory)->inspect($root)->passed);
        file_put_contents($root . '/changed.php', '<?php // changed');
        self::assertContains('SITE036_MANAGEMENT_SOURCE_DRIFT', array_column(new SiteDoctorService($inventory)->inspect($root)->findings, 'id'));
    }

    public function testMalformedCompanionIsAStableDiagnostic(): void
    {
        $root = $this->fixture(); file_put_contents($root . '/.waaseyaa/management.json', '{');
        $report = new SiteDoctorService()->inspect($root);
        self::assertSame(['SITE030_INVALID_MANAGEMENT'], array_column($report->findings, 'id'));
        self::assertFalse($report->passed);
    }
    public function testSpecificParserDiagnosticReachesDoctor(): void
    {
        $root = $this->fixture();
        $document = F::document(); $document['version'] = 99;
        file_put_contents($root . '/.waaseyaa/management.json', json_encode($document, JSON_THROW_ON_ERROR));
        self::assertSame(['SITE031_UNSUPPORTED_MANAGEMENT_VERSION'],
            array_column(new SiteDoctorService()->inspect($root)->findings, 'id'));
    }
    public static function managementInputs(): iterable
    {
        yield 'Go' => ['main.go'];
        yield 'SQL' => ['schema.sql'];
        yield 'root acceptance tests' => ['tests/receipt-check.php'];
        yield 'extensionless executable' => ['bin/verify'];
        yield 'Composer lock' => ['composer.lock'];
        yield 'Go lock' => ['go.sum'];
        yield 'frontend lock' => ['package-lock.json'];
        yield 'hidden configuration' => ['.config'];
        yield 'installed dependency' => ['vendor/library/runtime.php'];
    }

    #[DataProvider('managementInputs')]
    public function testEveryManagementInputInvalidatesAnExecutedReceipt(string $path): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/.waaseyaa/management.json', json_encode(F::document(), JSON_THROW_ON_ERROR));
        $absolute = $root . '/' . $path;
        new Filesystem()->mkdir(dirname($absolute));
        if (!is_file($absolute)) {
            file_put_contents($absolute, 'before');
        }
        $operation = new ManagementManifestParser()->operation(F::operation());
        $site = new SiteManifestParser()->parse(file_get_contents($root . '/.waaseyaa/site.yaml'));
        $identity = new ManagementInputDiscovery()->digest($root);
        $inventory = F::inventory([$operation], null, $identity, $site->digest);
        $receipt = new SiteDoctorService($inventory)->inspect($root);
        self::assertTrue($receipt->passed);
        self::assertSame($identity, $receipt->toArray()['management_input_sha256']);
        $architectureDigest = new ProjectSourceDiscovery()->discover($root)->digest;
        file_put_contents($absolute, 'after');
        if ($path !== 'package-lock.json') {
            self::assertSame($architectureDigest, new ProjectSourceDiscovery()->discover($root)->digest);
        }
        self::assertContains('SITE036_MANAGEMENT_SOURCE_DRIFT', array_column(new SiteDoctorService($inventory)->inspect($root)->findings, 'id'));
        $current = F::inventory([$operation], iterator_to_array($inventory->verificationResults()),
            new ManagementInputDiscovery()->digest($root), $site->digest);
        self::assertContains('SITE041_MANAGEMENT_VERIFICATION_UNPROVEN', array_column(new SiteDoctorService($current)->inspect($root)->findings, 'id'));
    }

    public function testUnestablishedInputIdentityRefusesTheReceipt(): void
    {
        $root = $this->fixture();
        file_put_contents($root . '/.waaseyaa/management.json', json_encode(F::document(), JSON_THROW_ON_ERROR));
        file_put_contents($root . '/outside.php', '<?php');
        self::assertTrue(symlink($root . '/outside.php', $root . '/redirected.php'));
        self::assertContains('SITE037_MANAGEMENT_INVENTORY_UNAVAILABLE', array_column(new SiteDoctorService()->inspect($root)->findings, 'id'));
    }

}
