<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\Site\SiteDoctorService;
use Waaseyaa\CLI\Site\SiteInitializationService;
use Waaseyaa\SiteContract\Blueprint\BlueprintDecisionReceipt;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\Doctor\SiteDoctorFinding;
use Waaseyaa\SiteContract\SiteManifestParser;

#[CoversClass(SiteDoctorService::class)]
final class SiteDoctorBlueprintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_blueprint_doctor_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o755, true);
        file_put_contents($this->root . '/composer.json', "{}\n");
        file_put_contents($this->root . '/composer.lock', "{}\n");
        $yaml = (string) file_get_contents(dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/minimal.yaml');
        $yaml = str_replace(str_repeat('a', 64), hash('sha256', "{}\n"), $yaml);
        $manifest = new SiteManifestParser()->parse($yaml);
        $receipt = BlueprintDecisionReceipt::fromArray([
            'schema' => BlueprintDecisionReceipt::SCHEMA_ID,
            'version' => BlueprintDecisionReceipt::CONTRACT_VERSION,
            'decision' => 'approved',
            'blueprint_digest' => $manifest->applicationBlueprint->digest,
            'manifest_digest' => $manifest->digest,
            'actor' => 'operator',
            'decided_at' => '2026-09-05T12:00:00Z',
            'mechanism' => 'manual-review',
        ]);
        new SiteInitializationService($this->root)->initialize(
            ApplicationBlueprintCompilerFactory::create()->compile($manifest),
            decisionReceipt: $receipt,
        );
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function test_exact_applied_blueprint_passes_without_changing_the_closed_report_shape(): void
    {
        $before = $this->snapshot();
        $report = new SiteDoctorService()->inspect($this->root);
        self::assertSame([], $report->findings);
        self::assertSame($before, $this->snapshot());
        self::assertArrayNotHasKey('application_blueprint', $report->toArray());
    }

    #[DataProvider('invalidEvidenceCases')]
    public function test_evidence_faults_fail_read_only_even_when_artifact_bytes_are_intact(string $case): void
    {
        $path = $this->root . '/.waaseyaa/generated.json';
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if ($case === 'missing') {
            unset($data['application_blueprint']);
        } elseif ($case === 'wrong-feature') {
            $data['application_blueprint']['generator_feature'] = 'unapproved-feature';
        } elseif ($case === 'extra-key') {
            $data['application_blueprint']['applied'] = true;
        } elseif ($case === 'rejected') {
            $data['application_blueprint']['decision_receipt']['decision'] = 'rejected';
        } else {
            $data['application_blueprint']['decision_receipt'][$case] = str_repeat('b', 64);
        }
        file_put_contents($path, CanonicalJson::encode($data) . "\n");
        $before = $this->snapshot();
        $report = new SiteDoctorService()->inspect($this->root);

        self::assertNotEmpty($report->findings);
        self::assertSame('SITE010_GENERATED_ARTIFACT_DRIFT', $report->findings[0]->id);
        self::assertSame('.waaseyaa/generated.json', $report->findings[0]->path);
        self::assertSame($before, $this->snapshot());
    }

    public static function invalidEvidenceCases(): iterable
    {
        foreach (['missing', 'wrong-feature', 'extra-key', 'rejected', 'blueprint_digest', 'manifest_digest'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_removing_both_provider_roster_and_registration_cannot_hide_a_missing_effect(): void
    {
        $metadataPath = $this->root . '/.waaseyaa/generated.json';
        $data = json_decode((string) file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
        unset($data['registrations']);
        file_put_contents($metadataPath, CanonicalJson::encode($data) . "\n");
        file_put_contents($this->root . '/composer.json', "{}\n");
        $before = $this->snapshot();
        $report = new SiteDoctorService()->inspect($this->root);

        self::assertNotEmpty($report->findings, 'A matching receipt cannot certify a missing provider effect.');
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_map(static fn(SiteDoctorFinding $finding): string => $finding->id, $report->findings));
        self::assertSame($before, $this->snapshot());
    }

    public function test_matching_evidence_cannot_certify_substituted_generated_code(): void
    {
        file_put_contents($this->root . '/src/Entity/Article.php', "<?php\n// substituted\n");
        $before = $this->snapshot();
        $report = new SiteDoctorService()->inspect($this->root);
        self::assertNotEmpty($report->findings);
        self::assertContains('SITE010_GENERATED_ARTIFACT_DRIFT', array_map(static fn(SiteDoctorFinding $finding): string => $finding->id, $report->findings));
        self::assertSame($before, $this->snapshot());
    }

    public function test_applied_evidence_cannot_survive_removal_of_the_authored_blueprint(): void
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 4) . '/site-contract/tests/Fixtures/Blueprint/valid/old-v1-without-blueprint.yaml');
        $yaml = str_replace(str_repeat('a', 64), hash('sha256', "{}\n"), $yaml);
        file_put_contents($this->root . '/.waaseyaa/site.yaml', $yaml);
        $before = $this->snapshot();
        $report = new SiteDoctorService()->inspect($this->root);

        self::assertNotEmpty($report->findings);
        self::assertSame('SITE010_GENERATED_ARTIFACT_DRIFT', $report->findings[0]->id);
        self::assertSame('.waaseyaa/generated.json', $report->findings[0]->path);
        self::assertSame($before, $this->snapshot());
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $result = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $result[substr($file->getPathname(), strlen($this->root) + 1)] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($result);

        return $result;
    }
}
