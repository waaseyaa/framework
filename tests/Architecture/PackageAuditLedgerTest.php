<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/lib/package-coverage.php';
require_once dirname(__DIR__, 2) . '/bin/lib/package-audit-ledger.php';

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): package audit ledgers
 * (docs/audits/packages/<package>.ledger.json) are read mechanically by later
 * audits, so every committed ledger must satisfy bin/lib/package-audit-ledger.php
 * and agree with its human record and its coverage-index row. The seeded
 * defects prove each class of check can fail.
 */
#[CoversNothing]
final class PackageAuditLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_committed_ledger_is_valid_and_agrees_with_its_record_and_index(): void
    {
        $rows = [];
        foreach (\packageCoverageIndex($this->root)['packages'] as $row) {
            $rows[$row['package']] = $row;
        }
        $ledgers = glob($this->root . '/docs/audits/packages/*.ledger.json') ?: [];
        foreach ($ledgers as $file) {
            $relative = 'docs/audits/packages/' . basename($file);
            $ledger = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($ledger, $relative);
            self::assertSame([], \packageAuditLedgerErrors($ledger), $relative);
            $recordFile = $this->root . '/' . substr($relative, 0, -strlen('.ledger.json')) . '.md';
            self::assertSame([], \packageAuditLedgerRepositoryErrors(
                $relative,
                $ledger,
                $rows[$ledger['package']] ?? null,
                is_file($recordFile) ? (string) file_get_contents($recordFile) : null,
                fn(string $path): bool => is_file($this->root . '/' . $path),
            ), $relative);
        }
        self::assertSame([], \packageAuditLedgerErrors(self::validLedger()), 'the reference ledger stays valid');
    }

    #[Test]
    public function a_minimal_valid_ledger_passes_both_layers(): void
    {
        $ledger = self::validLedger();
        self::assertSame([], \packageAuditLedgerErrors($ledger));
        self::assertSame([], \packageAuditLedgerRepositoryErrors(
            'docs/audits/packages/demo.ledger.json',
            $ledger,
            self::indexRow(),
            self::record(),
            static fn(string $path): bool => true,
        ));
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[Test]
    #[DataProvider('documentDefects')]
    public function each_document_defect_is_reported(\Closure $mutate, string $expected): void
    {
        $errors = \packageAuditLedgerErrors($mutate(self::validLedger()));
        self::assertNotSame([], $errors, 'the defect was accepted');
        self::assertStringContainsString($expected, implode("\n", $errors));
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function documentDefects(): iterable
    {
        $finding = static fn(array $l, string $id, string $key, mixed $value): array => self::withFinding($l, $id, static function (array $f) use ($key, $value): array {
            $f[$key] = $value;

            return $f;
        });

        yield 'unknown top-level key' => [static fn(array $l): array => $l + ['extra' => 1], "unknown top-level key 'extra'"];
        yield 'missing top-level key' => [static function (array $l): array {
            unset($l['handoffs']);

            return $l;
        }, "missing top-level key 'handoffs'"];
        yield 'short base' => [static fn(array $l): array => ['base' => 'abc123'] + $l, 'base must be a full 40-hex commit id'];
        yield 'repair ready before assessed' => [static function (array $l): array {
            $l['milestone'] = ['assessed' => false, 'repair_ready' => true, 'converged' => null, 'reasons' => ['one item open']];

            return $l;
        }, 'repair_ready requires milestone.assessed'];
        yield 'not assessed without reasons' => [static function (array $l): array {
            $l['milestone']['assessed'] = false;
            $l['milestone']['reasons'] = [];

            return $l;
        }, 'must say why'];
        yield 'unknown severity' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'severity', 'serious'), 'unknown severity'];
        yield 'owner not one package' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'owned_by', 'waaseyaa/audit and waaseyaa/access'), 'owner-format'];
        yield 'dangling decision' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'decision', 'D9'), 'unknown decision D9'];
        yield 'blocking without decision' => [static function (array $l) use ($finding): array {
            $l = $finding($l, 'DEMO-DOMAIN-001', 'blocks_assessment', true);

            return $finding($l, 'DEMO-DOMAIN-001', 'decision', null);
        }, 'blocks the assessment but names no maintainer decision'];
        yield 'slice that does not list the finding' => [static function (array $l): array {
            $l['remediation_plan'][0]['findings'] = ['DEMO-TEST-001'];

            return $l;
        }, "slice S1 doesn't list it"];
        yield 'unknown destination kind' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'destination', [['kind' => 'someday', 'ref' => 'x']]), 'known kind'];
        yield 'merged into a missing carrier' => [static fn(array $l): array => $finding($l, 'DEMO-TEST-001', 'merged_into', 'DEMO-TEST-099'), 'merged_into must name another finding'];
        yield 'medium finding below tier A' => [static function (array $l) use ($finding): array {
            $l = $finding($l, 'DEMO-DOMAIN-001', 'severity', 'medium');

            return $finding($l, 'DEMO-DOMAIN-001', 'verification', ['tier' => 'B', 'performed' => 'one verifier', 'reversals' => []]);
        }, 'need tier A'];
        yield 'unverified finding in an assessed ledger' => [static fn(array $l): array => $finding($l, 'DEMO-TEST-001', 'verification', ['tier' => 'pending', 'performed' => 'not yet', 'reversals' => []]), "can't hold unverified findings"];
        yield 'empty affected consumers' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'affected_consumers', []), 'affected_consumers must be a non-empty list'];
        yield 'security row with a public severity' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'severity', 'medium'), 'public severity must be withheld'];
        yield 'security row naming consumers' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'affected_consumers', ['an app']), 'affected_consumers must be withheld'];
        yield 'security row naming probes' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'probes', ['PrivateProbeTest.php']), 'probes must be a count'];
        yield 'security row citing file:line' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'observed', 'See src/Thing.php:42.'), 'observed cites a code location'];
        yield 'security row with co-owners' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'co_owners', ['waaseyaa/access']), 'co-owners stay in the private brief'];
        yield 'security row without a private report' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'destination', [['kind' => 'issue', 'ref' => '#1']]), 'must include a private report'];
        yield 'roster tying a security id to a file' => [static function (array $l): array {
            $l['roster'][0]['findings'] = ['DEMO-SEC-001'];

            return $l;
        }, 'ties security finding DEMO-SEC-001'];
        yield 'checklist finding without a finding' => [static function (array $l): array {
            $l['checklists'][0]['status'] = 'finding';
            $l['checklists'][0]['findings'] = [];

            return $l;
        }, 'names no finding'];
        yield 'unknown classification' => [static function (array $l): array {
            $l['roster'][0]['classification'] = 'fine';

            return $l;
        }, 'unknown classification'];
        yield 'handed-off lead that blocks' => [static function (array $l): array {
            $l['handoffs'][0]['blocks_assessment'] = true;

            return $l;
        }, "can't be handed off"];
        yield 'supported profile without evidence or owner' => [static function (array $l): array {
            $l['qualification'][1]['gap_owner'] = null;

            return $l;
        }, 'needs a gap owner'];
        yield 'retained probe outside the flat layout' => [static function (array $l): array {
            $l['probes'][0]['retained_path'] = 'scratch/probe.php';

            return $l;
        }, 'retained_path must be'];
        yield 'duplicate refuted id' => [static function (array $l): array {
            $l['refuted'][] = $l['refuted'][0];

            return $l;
        }, 'unique <PKG>-R-NNN id'];
        yield 'finding id that is also refuted' => [static function (array $l): array {
            $l['refuted'][0]['id'] = 'DEMO-R-001';
            $l['findings'][] = self::finding('DEMO-R-001', 'R', 'low');

            return $l;
        }, 'is both a finding and a refuted lead'];

        // Types: every field, nested ones included.
        yield 'verification performed null' => [static fn(array $l): array => $finding($l, 'DEMO-TEST-001', 'verification', ['tier' => 'B', 'performed' => null, 'reversals' => []]), 'verification: performed must be a non-empty string'];
        yield 'verification reversals not strings' => [static fn(array $l): array => $finding($l, 'DEMO-TEST-001', 'verification', ['tier' => 'B', 'performed' => 'one verifier', 'reversals' => [1]]), 'reversals must be a list of strings'];
        yield 'observed as a list' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'observed', ['src/Demo.php:9']), 'observed must be a non-empty string'];
        yield 'empty acceptance' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'acceptance', ' '), 'acceptance must be a non-empty string'];
        yield 'notes as a number' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'notes', 3), 'notes must be a string'];
        yield 'charter evidence null' => [static function (array $l): array {
            $l['charter'][0]['evidence'] = null;

            return $l;
        }, 'charter[0]: evidence must be a non-empty string'];
        yield 'handoff issue empty' => [static function (array $l): array {
            $l['handoffs'][0]['issue'] = '';

            return $l;
        }, 'handoffs[0]: issue must be null or a non-empty string'];
        yield 'evidence run result missing' => [static function (array $l): array {
            $l['evidence_runs'][0]['result'] = false;

            return $l;
        }, 'evidence_runs[0]: result must be a non-empty string'];

        // Security redaction reaches every nested string, and every neighbour.
        yield 'security locator nested in a destination' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'destination', [['kind' => 'private-report', 'ref' => 'see src/Sensitive.php:42']]), 'destination cites a code location'];
        yield 'security locator nested in reversals' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'verification', ['tier' => 'A', 'performed' => 'two verifiers', 'reversals' => ['moved from src/Sensitive.php:42']]), 'verification cites a code location'];
        yield 'security row naming a symbol' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'observed', 'The branch in Guard::check() skips it.'), 'observed cites a code location'];
        yield 'security row naming a file' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'title', 'A flaw in GroupAccessPolicy.php'), 'title cites a code location'];
        yield 'security row citing a line anchor' => [static fn(array $l): array => $finding($l, 'DEMO-SEC-001', 'notes', 'docs/specs/access.md#L12'), 'notes cites a code location'];
        yield 'decision tying a security id to code' => [static function (array $l): array {
            $l['decisions'][0]['decision'] = 'DEMO-SEC-001 turns on src/Demo.php';

            return $l;
        }, 'decisions[0] names a security finding next to a code location'];
        yield 'sibling finding tying a security id to code' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'notes', 'the same branch as DEMO-SEC-001'), 'finding DEMO-DOMAIN-001 names a security finding next to a code location'];
        yield 'scorecard string tying a security id to code' => [static function (array $l): array {
            $l['scorecard']['note'] = ['DEMO-SEC-001 reproduced by tests/SecretProbe.php'];

            return $l;
        }, 'scorecard names a security finding next to a code location'];

        // Decisions and uncertainties say whether they are open.
        yield 'uncertainty without a status' => [static function (array $l): array {
            $l['uncertainties'] = [['id' => 'U1', 'uncertainty' => 'which', 'findings' => [], 'what_would_settle_it' => 'a probe']];

            return $l;
        }, 'uncertainties[0] must have exactly id, uncertainty, findings, what_would_settle_it, status, resolution'];
        yield 'unknown status' => [static function (array $l): array {
            $l['decisions'][0]['status'] = 'pending';

            return $l;
        }, 'status must be open or settled'];
        yield 'settled without a resolution' => [static function (array $l): array {
            $l['decisions'][0]['status'] = 'settled';

            return $l;
        }, 'a settled entry needs its resolution'];
        yield 'open with a resolution' => [static function (array $l): array {
            $l['decisions'][0]['resolution'] = 'decided already';

            return $l;
        }, 'an open entry has no resolution yet'];
        yield 'scorecard open count drift' => [static function (array $l): array {
            $l['scorecard']['open']['uncertainties'] = 2;

            return $l;
        }, 'scorecard.open.uncertainties is 2 but 0 uncertainties are open'];
        yield 'scorecard without open counts' => [static function (array $l): array {
            unset($l['scorecard']['open']);

            return $l;
        }, 'scorecard.open must give the open decisions and uncertainties'];
        yield 'assessed with a blocking decision open' => [static fn(array $l): array => $finding($l, 'DEMO-DOMAIN-001', 'blocks_assessment', true), 'needs decision D1 settled'];
    }

    /**
     * @param \Closure(array<string, mixed>, array<string, mixed>, string): array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: string|null} $mutate
     */
    #[Test]
    #[DataProvider('repositoryDefects')]
    public function each_repository_disagreement_is_reported(\Closure $mutate, string $expected): void
    {
        [$ledger, $row, $record] = $mutate(self::validLedger(), self::indexRow(), self::record());
        $errors = \packageAuditLedgerRepositoryErrors('docs/audits/packages/demo.ledger.json', $ledger, $row, $record, static fn(string $path): bool => !str_contains($path, 'Missing'));
        self::assertStringContainsString($expected, implode("\n", $errors));
    }

    /** @return iterable<string, array{\Closure, string}> */
    public static function repositoryDefects(): iterable
    {
        yield 'no index row' => [static fn(array $l, array $r, string $m): array => [$l, null, $m], 'coverage index has no row'];
        yield 'index does not cite the ledger' => [static function (array $l, array $r, string $m): array {
            $r['evidence'] = [['kind' => 'repository-path', 'path' => 'docs/audits/packages/demo.md']];

            return [$l, $r, $m];
        }, "doesn't cite docs/audits/packages/demo.ledger.json"];
        yield 'base disagrees' => [static function (array $l, array $r, string $m): array {
            $r['audit_base'] = str_repeat('b', 40);

            return [$l, $r, $m];
        }, 'base differs'];
        yield 'lock identity disagrees' => [static function (array $l, array $r, string $m): array {
            $r['dependency_identity'] = 'composer.lock sha256:' . str_repeat('c', 64);

            return [$l, $r, $m];
        }, 'dependency identity differs'];
        yield 'assessed disagrees' => [static function (array $l, array $r, string $m): array {
            $r['audit_state'] = 'in progress';

            return [$l, $r, $m];
        }, 'disagrees with the coverage index audit_state'];
        yield 'record missing' => [static fn(array $l, array $r, string $m): array => [$l, $r, null], 'human record) is missing'];
        yield 'record omits a finding' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace('DEMO-DOMAIN-001', 'DEMO-DOMAIN-00X', $m)], "doesn't mention DEMO-DOMAIN-001"];
        yield 'medium finding without a detail block' => [static function (array $l, array $r, string $m): array {
            $l = self::withFinding($l, 'DEMO-DOMAIN-001', static fn(array $f): array => ['severity' => 'medium'] + $f);

            return [$l, $r, $m];
        }, 'no detail block for medium-or-higher DEMO-DOMAIN-001'];
        yield 'retained probe not committed' => [static function (array $l, array $r, string $m): array {
            $l['probes'][0]['retained_path'] = 'tests/Fixtures/Audits/Missing/DEMO-DOMAIN-001-probe.php';
            $r['evidence'][] = ['kind' => 'repository-path', 'path' => 'tests/Fixtures/Audits/Missing/DEMO-DOMAIN-001-probe.php'];

            return [$l, $r, $m];
        }, 'is not committed'];
        yield 'wrong ledger path' => [static function (array $l, array $r, string $m): array {
            $l['package'] = 'waaseyaa/other';

            return [$l, $r, $m];
        }, 'must live at docs/audits/packages/other.ledger.json'];
        yield 'record count drift' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace('1 checklist answers', '98 checklist answers', $m)], 'says "98 checklist answers"; the ledger has 1'];
        yield 'record omits a count' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace(', 1 evidence runs', '', $m)], 'doesn\'t state "N evidence runs"'];
        yield 'record without a structured ledger line' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace('**Structured ledger:**', '**Ledger:**', $m)], 'no "Structured ledger" header line'];
        yield 'record claims no open uncertainties' => [static function (array $l, array $r, string $m): array {
            $l['uncertainties'] = [['id' => 'U1', 'uncertainty' => 'which', 'findings' => [], 'what_would_settle_it' => 'a probe', 'status' => 'open', 'resolution' => null]];

            return [$l, $r, str_replace('0 open uncertainties', 'no open uncertainties', $m)];
        }, 'says "no open uncertainties"; the ledger has 1 open'];
        yield 'open decision missing from the record table' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace('| D1 |', '| D01 |', $m)], 'has no row for open D1'];
        yield 'record audit state disagrees' => [static fn(array $l, array $r, string $m): array => [$l, $r, str_replace('**Audit state:** assessed.', '**Audit state:** in progress.', $m)], "the record's audit state (in progress) disagrees"];
        yield 'record line tying a security id to code' => [static fn(array $l, array $r, string $m): array => [$l, $r, $m . "DEMO-SEC-001 sits in src/Demo.php:3.\n"], 'names a security finding next to a code location'];
    }

    /** @return array<string, mixed> */
    private static function validLedger(): array
    {
        $question = static fn(string $q): array => ['question' => $q, 'answer' => 'answered from code', 'evidence' => 'src/Demo.php:1'];

        return [
            'schema_version' => 1,
            'package' => 'waaseyaa/demo',
            'base' => str_repeat('a', 40),
            'audit_date' => '2026-09-26',
            'dependency_identity' => ['composer_lock_sha256' => str_repeat('d', 64), 'php' => '8.5.5', 'host' => 'native Windows 11'],
            'milestone' => ['assessed' => true, 'repair_ready' => false, 'converged' => null, 'reasons' => []],
            'charter' => array_map($question, ['owns', 'consumers', 'dependencies', 'public surface', 'profiles and evidence']),
            'roster' => [['file' => 'src/Demo.php', 'role' => 'the service', 'classification' => 'necessary but under-specified', 'evidence_level' => 'reviewed', 'notes' => '', 'findings' => ['DEMO-DOMAIN-001']]],
            'checklists' => [['profile' => 'domain-contracts', 'item' => 'invariants', 'status' => 'finding', 'answer' => 'one invariant is unenforced', 'evidence' => 'src/Demo.php:9', 'findings' => ['DEMO-DOMAIN-001']]],
            'intake' => [],
            'findings' => [
                self::finding('DEMO-DOMAIN-001', 'DOMAIN', 'low'),
                self::finding('DEMO-TEST-001', 'TEST', 'info'),
                self::finding('DEMO-TEST-002', 'TEST', 'info', mergedInto: 'DEMO-TEST-001'),
                self::finding('DEMO-SEC-001', 'SEC', 'withheld'),
            ],
            'refuted' => [['id' => 'DEMO-R-001', 'lead' => 'a lead', 'investigation' => 'read it', 'refutation' => 'it holds', 'evidence' => 'src/Demo.php:3']],
            'handoffs' => [['id' => 'H1', 'lead' => 'another package', 'owner' => 'waaseyaa/access', 'co_owners' => [], 'affected_consumers' => ['none'], 'issue' => null, 'blocks_assessment' => false]],
            'decisions' => [['id' => 'D1', 'decision' => 'which way', 'findings' => ['DEMO-DOMAIN-001'], 'what_would_settle_it' => 'a ruling', 'who_decides' => 'the maintainer', 'status' => 'open', 'resolution' => null]],
            'uncertainties' => [],
            'issue_reconciliation' => [],
            'remediation_plan' => [['slice' => 'S1', 'findings' => ['DEMO-DOMAIN-001'], 'acceptance' => 'a failing test that passes after the fix', 'depends_on' => ['D1']]],
            'qualification' => [
                ['profile' => 'kernel composition', 'supported' => 'yes', 'evidence_class' => 'closure artifact', 'evidence' => 'hosted run 1, job 2', 'gap_owner' => null],
                ['profile' => 'standalone split', 'supported' => 'yes', 'evidence_class' => 'none', 'evidence' => 'not run', 'gap_owner' => 'framework CI'],
            ],
            'probes' => [['name' => 'DomainProbeTest.php', 'purpose' => 'shows the gap', 'result' => 'reproduced', 'retained_path' => null, 'reproduce' => 'phpunit DomainProbeTest.php']],
            'evidence_runs' => [['command' => 'phpunit packages/demo/tests', 'base' => str_repeat('a', 40), 'dependency_identity' => 'lock', 'runner' => 'local', 'host' => 'native Windows 11', 'proves' => 'source', 'result' => 'OK']],
            'not_reviewed' => [],
            'host_limits' => [],
            'scorecard' => ['agents' => 12, 'open' => ['decisions' => 1, 'uncertainties' => 0]],
        ];
    }

    /** @return array<string, mixed> */
    private static function finding(string $id, string $area, string $severity, ?string $mergedInto = null): array
    {
        $security = $area === 'SEC';

        return [
            'id' => $id,
            'title' => $security ? 'A safe class-of-issue sentence' : 'A finding',
            'area' => $area,
            'severity' => $severity,
            'confidence' => 'confirmed',
            'evidence_level' => 'reproduced',
            'observed' => $security ? 'Withheld.' : 'src/Demo.php:9 does the wrong thing',
            'expected_contract' => 'the spec',
            'consequence' => 'a consumer sees it',
            'refutation' => 'considered and rejected',
            'disposition' => 'repair',
            'destination' => $security
                ? [['kind' => 'private-report', 'ref' => 'framework advisory; owner waaseyaa/demo']]
                : ($area === 'DOMAIN' ? [['kind' => 'slice', 'ref' => 'S1'], ['kind' => 'decision', 'ref' => 'D1']] : [['kind' => 'issue', 'ref' => '#1']]),
            'dependencies' => 'none',
            'acceptance' => 'a discriminating test',
            'residual_risk' => 'none',
            'next_action' => 'file S1',
            'discovered_in' => 'waaseyaa/demo',
            'owned_by' => 'waaseyaa/demo',
            'co_owners' => [],
            'affected_consumers' => $security ? 'withheld' : ['none'],
            'blocks_assessment' => false,
            'decision' => $area === 'DOMAIN' ? 'D1' : null,
            'consumer_unblock' => null,
            'merged_into' => $mergedInto,
            'verification' => ['tier' => $security ? 'A' : 'B', 'performed' => 'verified', 'reversals' => []],
            'probes' => $security ? 2 : ['DomainProbeTest.php'],
            'notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $ledger
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private static function withFinding(array $ledger, string $id, \Closure $change): array
    {
        foreach ($ledger['findings'] as $i => $finding) {
            if ($finding['id'] === $id) {
                $ledger['findings'][$i] = $change($finding);
            }
        }

        return $ledger;
    }

    /** @return array<string, mixed> */
    private static function indexRow(): array
    {
        return [
            'package' => 'waaseyaa/demo',
            'audit_state' => 'assessed',
            'audit_base' => str_repeat('a', 40),
            'audit_date' => '2026-09-26',
            'dependency_identity' => 'composer.lock sha256:' . str_repeat('d', 64),
            'evidence' => [
                ['kind' => 'repository-path', 'path' => 'docs/audits/packages/demo.md'],
                ['kind' => 'repository-path', 'path' => 'docs/audits/packages/demo.ledger.json'],
            ],
        ];
    }

    private static function record(): string
    {
        return <<<'MD'
            # `waaseyaa/demo` audit

            - **Audit state:** assessed. **Remediation state:** planned.
            - **Structured ledger:** `docs/audits/packages/demo.ledger.json`: 4 findings, 1 refuted leads, 1 checklist answers, 1 handoffs, 1 decisions (1 open), 0 uncertainties (0 open), 1 probe entries, 1 evidence runs.

            | `DEMO-DOMAIN-001` | ... |
            | `DEMO-TEST-001` | ... |
            | `DEMO-SEC-001` | A safe class-of-issue sentence | withheld |

            | D1 | which way | DEMO-DOMAIN-001 | a ruling | the maintainer |

            - **Open:** 1 open decisions; 0 open uncertainties.

            MD;
    }
}
