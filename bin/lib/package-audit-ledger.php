<?php

declare(strict_types=1);

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): the executable contract for package audit
 * ledgers, docs/audits/packages/<package>.ledger.json.
 *
 * Later audits read ledgers mechanically (their intake is every finding and
 * handoff another ledger routes to them), so a malformed key, enum, owner,
 * reference or security row would corrupt them silently. The shape is the one
 * the package-convergence skill's audit record template documents;
 * tests/Architecture/PackageAuditLedgerTest.php runs these checks over every
 * committed ledger and seeds each defect class.
 *
 * Two layers:
 * - packageAuditLedgerErrors(): the document on its own (keys, types, enums,
 *   internal references, security-row redaction rules);
 * - packageAuditLedgerRepositoryErrors(): the document against its record, its
 *   coverage-index row and the retained probe files.
 *
 * Draft check (for example a ledger still in a scratch directory):
 *
 *   php bin/lib/package-audit-ledger.php <ledger.json> [--record=<record.md>] [--index=<coverage-index.json>]
 *
 * Plain functions, no autoloader.
 */

const PACKAGE_AUDIT_LEDGER_TOP_KEYS = [
    'schema_version', 'package', 'base', 'audit_date', 'dependency_identity', 'milestone',
    'charter', 'roster', 'checklists', 'intake', 'findings', 'refuted', 'handoffs',
    'decisions', 'uncertainties', 'issue_reconciliation', 'remediation_plan',
    'qualification', 'probes', 'evidence_runs', 'not_reviewed', 'host_limits', 'scorecard',
];

const PACKAGE_AUDIT_LEDGER_FINDING_KEYS = [
    'id', 'title', 'area', 'severity', 'confidence', 'evidence_level', 'observed',
    'expected_contract', 'consequence', 'refutation', 'disposition', 'destination',
    'dependencies', 'acceptance', 'residual_risk', 'next_action', 'discovered_in',
    'owned_by', 'co_owners', 'affected_consumers', 'blocks_assessment', 'decision',
    'consumer_unblock', 'merged_into', 'verification', 'probes', 'notes',
];

const PACKAGE_AUDIT_LEDGER_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info', 'withheld'];
const PACKAGE_AUDIT_LEDGER_CONFIDENCE = ['confirmed', 'likely', 'suspected'];
const PACKAGE_AUDIT_LEDGER_LEVELS = ['inventoried', 'reviewed', 'reproduced', 'qualified'];
const PACKAGE_AUDIT_LEDGER_CLASSIFICATIONS = [
    'owned and coherent',
    'necessary but under-specified',
    'duplicated or drifting contract',
    'wrong package or layer',
    'unwired or obsolete',
    'optional/required misrepresented',
    'missing refusal, lifecycle, compatibility or distribution evidence',
];
const PACKAGE_AUDIT_LEDGER_CHECKLIST_STATUSES = ['answered', 'does not apply', 'finding', 'gap'];
const PACKAGE_AUDIT_LEDGER_DESTINATION_KINDS = ['issue', 'slice', 'owner-audit', 'private-report', 'decision', 'residual'];
const PACKAGE_AUDIT_LEDGER_TIERS = ['A', 'B', 'C', 'pending'];
const PACKAGE_AUDIT_LEDGER_INTAKE_DISPOSITIONS = ['confirmed', 'merged', 'refuted', 're-owned'];
const PACKAGE_AUDIT_LEDGER_PROFILES = ['primitive-only', 'standalone split', 'kernel composition', 'metapackage', 'framework closure', 'generated application'];
const PACKAGE_AUDIT_LEDGER_EVIDENCE_CLASSES = ['source', 'closure artifact', 'metapackage', 'installed consumer', 'standalone split', 'generated application', 'none'];
const PACKAGE_AUDIT_LEDGER_CONSUMER_UNBLOCK = ['required', 'independent', 'accepted limitation'];

const PACKAGE_AUDIT_LEDGER_OWNER = '#^(waaseyaa/[a-z0-9][a-z0-9-]*|external:[A-Za-z0-9][A-Za-z0-9._/-]*)$#';
const PACKAGE_AUDIT_LEDGER_FINDING_ID = '#^([A-Z][A-Z0-9]*)-([A-Z]+)-(\d{3})$#';
const PACKAGE_AUDIT_LEDGER_REFUTED_ID = '#^[A-Z][A-Z0-9]*-R-\d{3}$#';
const PACKAGE_AUDIT_LEDGER_RETAINED_PROBE = '#^tests/Fixtures/Audits/[A-Za-z0-9]+/[A-Z][A-Z0-9]*-[A-Z]+-\d{3}-[a-z0-9-]+\.php$#';
/** A file:line citation, the most direct way public text can localize a security finding. */
const PACKAGE_AUDIT_LEDGER_FILE_LINE = '#[A-Za-z0-9_./-]+\.(php|md|js|ts|vue|json|ya?ml|neon|sh):\d+#';

/**
 * Structural and internal-consistency problems of one ledger document.
 *
 * @param array<mixed> $ledger
 * @return list<string>
 */
function packageAuditLedgerErrors(array $ledger): array
{
    $errors = [];
    $err = static function (string $message) use (&$errors): void {
        $errors[] = $message;
    };

    $keys = array_keys($ledger);
    foreach (array_diff(PACKAGE_AUDIT_LEDGER_TOP_KEYS, $keys) as $missing) {
        $err("missing top-level key '$missing'");
    }
    foreach (array_diff($keys, PACKAGE_AUDIT_LEDGER_TOP_KEYS) as $unknown) {
        $err("unknown top-level key '$unknown'");
    }
    if ($errors !== []) {
        return $errors;
    }

    if ($ledger['schema_version'] !== 1) {
        $err('schema_version must be 1');
    }
    $package = $ledger['package'];
    if (!is_string($package) || preg_match('#^@?waaseyaa/[a-z0-9][a-z0-9-]*$#', $package) !== 1) {
        $err('package must be a waaseyaa package name');
        $package = '';
    }
    if (!packageAuditLedgerIsSha($ledger['base'])) {
        $err('base must be a full 40-hex commit id');
    }
    if (!is_string($ledger['audit_date']) || preg_match('#^\d{4}-\d{2}-\d{2}$#', $ledger['audit_date']) !== 1) {
        $err('audit_date must be YYYY-MM-DD');
    }
    $identity = $ledger['dependency_identity'];
    if (!is_array($identity) || !is_string($identity['composer_lock_sha256'] ?? null)
        || preg_match('#^[0-9a-f]{64}$#', $identity['composer_lock_sha256']) !== 1
        || !packageAuditLedgerIsText($identity['php'] ?? null) || !packageAuditLedgerIsText($identity['host'] ?? null)
    ) {
        $err('dependency_identity needs composer_lock_sha256 (64 hex), php and host');
    }

    $milestone = $ledger['milestone'];
    $assessed = false;
    if (!is_array($milestone) || array_keys($milestone) !== ['assessed', 'repair_ready', 'converged', 'reasons']) {
        $err('milestone must have exactly assessed, repair_ready, converged, reasons');
    } else {
        $assessed = $milestone['assessed'] === true;
        if (!is_bool($milestone['assessed']) || !is_bool($milestone['repair_ready'])) {
            $err('milestone.assessed and milestone.repair_ready must be booleans');
        }
        if ($milestone['repair_ready'] === true && !$assessed) {
            $err('milestone.repair_ready requires milestone.assessed');
        }
        $converged = $milestone['converged'];
        if ($converged !== null) {
            if (!is_array($converged) || !packageAuditLedgerIsSha($converged['sha'] ?? null) || !packageAuditLedgerIsText($converged['evidence'] ?? null)) {
                $err('milestone.converged must be null or {sha, evidence}');
            }
            if ($milestone['repair_ready'] !== true) {
                $err('milestone.converged requires milestone.repair_ready');
            }
        }
        if (!packageAuditLedgerIsTextList($milestone['reasons'], allowEmpty: true)) {
            $err('milestone.reasons must be a list of strings');
        } elseif (!$assessed && $milestone['reasons'] === []) {
            $err('a ledger that is not assessed must say why in milestone.reasons');
        }
    }

    foreach (['charter', 'roster', 'checklists', 'intake', 'findings', 'refuted', 'handoffs', 'decisions', 'uncertainties', 'issue_reconciliation', 'remediation_plan', 'qualification', 'probes', 'evidence_runs'] as $section) {
        if (!is_array($ledger[$section]) || !array_is_list($ledger[$section])) {
            $err("$section must be a list");

            return $errors;
        }
    }
    foreach (['not_reviewed', 'host_limits'] as $section) {
        if (!packageAuditLedgerIsTextList($ledger[$section], allowEmpty: true)) {
            $err("$section must be a list of strings");
        }
    }
    if (!is_array($ledger['scorecard']) || $ledger['scorecard'] === [] || array_is_list($ledger['scorecard'])) {
        $err('scorecard must be a non-empty object');
    }

    // Index the referenceable identifiers first.
    $findingIds = [];
    $securityIds = [];
    foreach ($ledger['findings'] as $i => $finding) {
        $id = is_array($finding) ? ($finding['id'] ?? null) : null;
        if (is_string($id)) {
            if (isset($findingIds[$id])) {
                $err("duplicate finding id $id");
            }
            $findingIds[$id] = $finding;
            if (packageAuditLedgerIsSecurity($finding)) {
                $securityIds[$id] = true;
            }
        } else {
            $err("findings[$i] has no string id");
        }
    }
    $decisionIds = packageAuditLedgerIndexIds($ledger['decisions'], '#^D\d+$#', 'decisions', $err);
    packageAuditLedgerIndexIds($ledger['uncertainties'], '#^U\d+$#', 'uncertainties', $err);
    packageAuditLedgerIndexIds($ledger['handoffs'], '#^H\d+$#', 'handoffs', $err);
    $slices = [];
    foreach ($ledger['remediation_plan'] as $i => $slice) {
        if (!is_array($slice) || array_keys($slice) !== ['slice', 'findings', 'acceptance', 'depends_on']) {
            $err("remediation_plan[$i] must have exactly slice, findings, acceptance, depends_on");
            continue;
        }
        if (!packageAuditLedgerIsText($slice['slice']) || isset($slices[$slice['slice']])) {
            $err("remediation_plan[$i] needs a unique slice name");
            continue;
        }
        $slices[$slice['slice']] = $slice;
        if (!packageAuditLedgerIsTextList($slice['findings']) || !packageAuditLedgerIsText($slice['acceptance'])
            || !packageAuditLedgerIsTextList($slice['depends_on'], allowEmpty: true)
        ) {
            $err("remediation_plan[{$slice['slice']}] needs findings, acceptance and depends_on");
            continue;
        }
        foreach ($slice['findings'] as $ref) {
            if (!isset($findingIds[$ref])) {
                $err("remediation_plan[{$slice['slice']}] references unknown finding $ref");
            }
        }
    }

    $refutedIds = [];
    foreach ($ledger['refuted'] as $i => $lead) {
        if (!is_array($lead) || array_keys($lead) !== ['id', 'lead', 'investigation', 'refutation', 'evidence']) {
            $err("refuted[$i] must have exactly id, lead, investigation, refutation, evidence");
            continue;
        }
        if (!is_string($lead['id']) || preg_match(PACKAGE_AUDIT_LEDGER_REFUTED_ID, $lead['id']) !== 1 || isset($refutedIds[$lead['id']])) {
            $err("refuted[$i] needs a unique <PKG>-R-NNN id");
            continue;
        }
        $refutedIds[$lead['id']] = true;
        if (!packageAuditLedgerIsText($lead['refutation'])) {
            $err("refuted {$lead['id']} has no refutation");
        }
    }

    foreach ($ledger['charter'] as $i => $entry) {
        if (!is_array($entry) || array_keys($entry) !== ['question', 'answer', 'evidence'] || !packageAuditLedgerIsText($entry['question']) || !packageAuditLedgerIsText($entry['answer'])) {
            $err("charter[$i] must have a question, an answer and evidence");
        }
    }
    if (count($ledger['charter']) < 5) {
        $err('charter must answer at least the five charter questions');
    }

    $files = [];
    foreach ($ledger['roster'] as $i => $row) {
        if (!is_array($row) || array_keys($row) !== ['file', 'role', 'classification', 'evidence_level', 'notes', 'findings']) {
            $err("roster[$i] must have exactly file, role, classification, evidence_level, notes, findings");
            continue;
        }
        if (!packageAuditLedgerIsText($row['file']) || isset($files[$row['file']])) {
            $err("roster[$i] needs a unique file");
            continue;
        }
        $files[$row['file']] = true;
        if (!in_array($row['classification'], PACKAGE_AUDIT_LEDGER_CLASSIFICATIONS, true)) {
            $err("roster {$row['file']}: unknown classification");
        }
        if (!in_array($row['evidence_level'], PACKAGE_AUDIT_LEDGER_LEVELS, true)) {
            $err("roster {$row['file']}: unknown evidence_level");
        }
        packageAuditLedgerCheckRefs($row['findings'], "roster {$row['file']}", $findingIds, $securityIds, $err);
    }
    if ($files === []) {
        $err('roster must list every production file');
    }

    foreach ($ledger['checklists'] as $i => $item) {
        if (!is_array($item) || array_keys($item) !== ['profile', 'item', 'status', 'answer', 'evidence', 'findings']) {
            $err("checklists[$i] must have exactly profile, item, status, answer, evidence, findings");
            continue;
        }
        if (!in_array($item['status'], PACKAGE_AUDIT_LEDGER_CHECKLIST_STATUSES, true)) {
            $err("checklists[$i]: unknown status");
        }
        if (!packageAuditLedgerIsText($item['profile']) || !packageAuditLedgerIsText($item['item']) || !packageAuditLedgerIsText($item['answer'])) {
            $err("checklists[$i] needs a profile, an item and an answer");
        }
        packageAuditLedgerCheckRefs($item['findings'], "checklists[$i]", $findingIds, $securityIds, $err);
        if ($item['status'] === 'finding' && $item['findings'] === []) {
            $err("checklists[$i] has status finding but names no finding");
        }
    }

    foreach ($ledger['intake'] as $i => $item) {
        if (!is_array($item) || array_keys($item) !== ['item', 'from', 'disposition', 'local_finding', 'notes']) {
            $err("intake[$i] must have exactly item, from, disposition, local_finding, notes");
            continue;
        }
        if (!in_array($item['disposition'], PACKAGE_AUDIT_LEDGER_INTAKE_DISPOSITIONS, true)) {
            $err("intake[$i]: unknown disposition");
        }
        if ($item['local_finding'] !== null && !isset($findingIds[$item['local_finding']])) {
            $err("intake[$i] references unknown local finding");
        }
    }

    foreach ($findingIds as $id => $finding) {
        packageAuditLedgerCheckFinding($id, $finding, $findingIds, $decisionIds, $slices, $assessed, $err);
    }
    foreach (array_keys($findingIds) as $id) {
        if (isset($refutedIds[$id])) {
            $err("$id is both a finding and a refuted lead");
        }
    }

    foreach ($ledger['handoffs'] as $i => $handoff) {
        if (!is_array($handoff) || array_keys($handoff) !== ['id', 'lead', 'owner', 'co_owners', 'affected_consumers', 'issue', 'blocks_assessment']) {
            $err("handoffs[$i] must have exactly id, lead, owner, co_owners, affected_consumers, issue, blocks_assessment");
            continue;
        }
        if (!packageAuditLedgerIsOwner($handoff['owner'])) {
            $err("handoff {$handoff['id']}: owner must be one package name in owner format");
        }
        if (!is_array($handoff['co_owners']) || array_filter($handoff['co_owners'], static fn(mixed $o): bool => !packageAuditLedgerIsOwner($o)) !== []) {
            $err("handoff {$handoff['id']}: co_owners must be owner-format names");
        }
        if ($handoff['blocks_assessment'] !== false) {
            $err("handoff {$handoff['id']}: a lead that blocks the assessment can't be handed off");
        }
    }

    foreach (['decisions' => ['id', 'decision', 'findings', 'what_would_settle_it', 'who_decides'], 'uncertainties' => ['id', 'uncertainty', 'findings', 'what_would_settle_it']] as $section => $shape) {
        foreach ($ledger[$section] as $i => $entry) {
            if (!is_array($entry) || array_keys($entry) !== $shape) {
                $err("{$section}[$i] must have exactly " . implode(', ', $shape));
                continue;
            }
            packageAuditLedgerCheckRefs($entry['findings'], "{$section} {$entry['id']}", $findingIds, [], $err);
        }
    }

    foreach ($ledger['issue_reconciliation'] as $i => $entry) {
        if (!is_array($entry) || array_keys($entry) !== ['issue', 'claim', 'status', 'evidence']) {
            $err("issue_reconciliation[$i] must have exactly issue, claim, status, evidence");
        }
    }

    foreach ($ledger['qualification'] as $i => $entry) {
        if (!is_array($entry) || array_keys($entry) !== ['profile', 'supported', 'evidence_class', 'evidence', 'gap_owner']) {
            $err("qualification[$i] must have exactly profile, supported, evidence_class, evidence, gap_owner");
            continue;
        }
        $profile = is_string($entry['profile']) ? preg_replace('# \(.*\)$#', '', $entry['profile']) : null;
        if (!in_array($profile, PACKAGE_AUDIT_LEDGER_PROFILES, true)) {
            $err("qualification[$i]: unknown installation profile");
        }
        if (!in_array($entry['evidence_class'], PACKAGE_AUDIT_LEDGER_EVIDENCE_CLASSES, true)) {
            $err("qualification[$i]: unknown evidence class");
        }
        if ($entry['evidence_class'] === 'source' && str_starts_with((string) $entry['evidence'], 'qualified')) {
            $err("qualification[$i]: source evidence is never qualified");
        }
        if (is_string($entry['supported']) && str_starts_with($entry['supported'], 'yes') && $entry['evidence_class'] === 'none' && !packageAuditLedgerIsText($entry['gap_owner'])) {
            $err("qualification[$i]: a supported profile without evidence needs a gap owner");
        }
    }

    foreach ($ledger['probes'] as $i => $probe) {
        if (!is_array($probe) || array_keys($probe) !== ['name', 'purpose', 'result', 'retained_path', 'reproduce']) {
            $err("probes[$i] must have exactly name, purpose, result, retained_path, reproduce");
            continue;
        }
        if ($probe['retained_path'] !== null && (!is_string($probe['retained_path']) || preg_match(PACKAGE_AUDIT_LEDGER_RETAINED_PROBE, $probe['retained_path']) !== 1)) {
            $err("probes[$i]: retained_path must be tests/Fixtures/Audits/<Package>/<FindingID>-<slug>.php");
        }
    }

    foreach ($ledger['evidence_runs'] as $i => $run) {
        if (!is_array($run) || array_keys($run) !== ['command', 'base', 'dependency_identity', 'runner', 'host', 'proves', 'result']) {
            $err("evidence_runs[$i] must have exactly command, base, dependency_identity, runner, host, proves, result");
        }
    }

    return $errors;
}

/**
 * @param array<string, mixed> $finding
 * @param array<string, mixed> $findingIds
 * @param array<string, true> $decisionIds
 * @param array<string, array<mixed>> $slices
 * @param callable(string): void $err
 */
function packageAuditLedgerCheckFinding(string $id, mixed $finding, array $findingIds, array $decisionIds, array $slices, bool $assessed, callable $err): void
{
    if (!is_array($finding) || array_keys($finding) !== PACKAGE_AUDIT_LEDGER_FINDING_KEYS) {
        $err("finding $id must have exactly the template's keys, in order");

        return;
    }
    if (preg_match(PACKAGE_AUDIT_LEDGER_FINDING_ID, $id, $m) !== 1) {
        $err("finding $id: id must be <PKG>-<AREA>-NNN");

        return;
    }
    if ($finding['area'] !== $m[2]) {
        $err("finding $id: area must match the id's area segment");
    }
    if (!packageAuditLedgerIsText($finding['title'])) {
        $err("finding $id has no title");
    }
    foreach (['severity' => PACKAGE_AUDIT_LEDGER_SEVERITIES, 'confidence' => PACKAGE_AUDIT_LEDGER_CONFIDENCE, 'evidence_level' => PACKAGE_AUDIT_LEDGER_LEVELS] as $key => $allowed) {
        if (!in_array($finding[$key], $allowed, true)) {
            $err("finding $id: unknown $key");
        }
    }
    if (!packageAuditLedgerIsOwner($finding['discovered_in']) || !packageAuditLedgerIsOwner($finding['owned_by'])) {
        $err("finding $id: discovered_in and owned_by must each be one owner-format name");
    }
    if (!is_array($finding['co_owners']) || !array_is_list($finding['co_owners'])
        || array_filter($finding['co_owners'], static fn(mixed $o): bool => !packageAuditLedgerIsOwner($o)) !== []
        || in_array($finding['owned_by'], $finding['co_owners'], true)
    ) {
        $err("finding $id: co_owners must be owner-format names other than owned_by");
    }
    if (!is_bool($finding['blocks_assessment'])) {
        $err("finding $id: blocks_assessment must be a boolean");
    }
    if ($finding['decision'] !== null && !isset($decisionIds[$finding['decision']])) {
        $err("finding $id references unknown decision {$finding['decision']}");
    }
    if ($finding['blocks_assessment'] === true && $finding['decision'] === null) {
        $err("finding $id blocks the assessment but names no maintainer decision");
    }
    if ($finding['consumer_unblock'] !== null) {
        $marking = is_string($finding['consumer_unblock']) ? $finding['consumer_unblock'] : '';
        $known = array_filter(PACKAGE_AUDIT_LEDGER_CONSUMER_UNBLOCK, static fn(string $v): bool => str_starts_with($marking, $v));
        if ($known === []) {
            $err("finding $id: consumer_unblock must be null or start with required, independent or accepted limitation");
        }
    }
    if ($finding['merged_into'] !== null) {
        $target = $findingIds[$finding['merged_into']] ?? null;
        if ($finding['merged_into'] === $id || !is_array($target)) {
            $err("finding $id: merged_into must name another finding");
        } elseif (($target['merged_into'] ?? null) !== null) {
            $err("finding $id: merged_into must name a carrier that isn't itself merged");
        }
    }

    $destination = $finding['destination'];
    if (!is_array($destination) || $destination === [] || !array_is_list($destination)) {
        $err("finding $id needs at least one destination");
        $destination = [];
    }
    foreach ($destination as $d) {
        if (!is_array($d) || array_keys($d) !== ['kind', 'ref'] || !in_array($d['kind'], PACKAGE_AUDIT_LEDGER_DESTINATION_KINDS, true) || !packageAuditLedgerIsText($d['ref'])) {
            $err("finding $id: each destination must be {kind, ref} with a known kind");
            continue;
        }
        if ($d['kind'] === 'decision' && !isset($decisionIds[$d['ref']])) {
            $err("finding $id: destination decision {$d['ref']} is not in decisions");
        }
        if ($d['kind'] === 'slice') {
            $slice = $slices[$d['ref']] ?? null;
            if ($slice === null) {
                $err("finding $id: destination slice {$d['ref']} is not in the remediation plan");
            } elseif (!in_array($id, $slice['findings'], true) && !in_array($finding['merged_into'], $slice['findings'], true)) {
                $err("finding $id: slice {$d['ref']} doesn't list it");
            }
        }
    }

    $verification = $finding['verification'];
    if (!is_array($verification) || array_keys($verification) !== ['tier', 'performed', 'reversals'] || !in_array($verification['tier'], PACKAGE_AUDIT_LEDGER_TIERS, true) || !is_array($verification['reversals'])) {
        $err("finding $id: verification must be {tier, performed, reversals} with a known tier");
    } else {
        if ($assessed && $verification['tier'] === 'pending') {
            $err("finding $id: an assessed ledger can't hold unverified findings");
        }
        $raised = in_array($finding['severity'], ['critical', 'high', 'medium'], true);
        if (($raised || packageAuditLedgerIsSecurity($finding)) && $verification['tier'] !== 'A' && $verification['tier'] !== 'pending') {
            $err("finding $id: security and medium-or-higher findings need tier A");
        }
    }

    if (packageAuditLedgerIsSecurity($finding)) {
        if ($finding['severity'] !== 'withheld') {
            $err("security finding $id: public severity must be withheld");
        }
        if ($finding['affected_consumers'] !== 'withheld') {
            $err("security finding $id: affected_consumers must be withheld");
        }
        if ($finding['co_owners'] !== []) {
            $err("security finding $id: co-owners stay in the private brief");
        }
        if (!is_int($finding['probes'])) {
            $err("security finding $id: probes must be a count, not names");
        }
        if (array_filter($destination, static fn(mixed $d): bool => is_array($d) && ($d['kind'] ?? null) === 'private-report') === []) {
            $err("security finding $id: destination must include a private report");
        }
        foreach (['title', 'observed', 'expected_contract', 'consequence', 'refutation', 'acceptance', 'residual_risk', 'next_action', 'notes', 'dependencies'] as $field) {
            if (is_string($finding[$field]) && preg_match(PACKAGE_AUDIT_LEDGER_FILE_LINE, $finding[$field]) === 1) {
                $err("security finding $id: $field cites a file:line");
            }
        }
    } else {
        if ($finding['severity'] === 'withheld') {
            $err("finding $id: only security findings are withheld");
        }
        $consumers = $finding['affected_consumers'];
        if (!packageAuditLedgerIsTextList($consumers)) {
            $err("finding $id: affected_consumers must be a non-empty list (write \"none\" when none)");
        }
        if (!is_array($finding['probes']) || !array_is_list($finding['probes'])) {
            $err("finding $id: probes must be a list of names");
        }
    }
}

/**
 * The ledger against its human record, its coverage-index row and the files
 * it says are retained.
 *
 * @param array<mixed> $ledger
 * @param array<string, mixed>|null $indexRow the package's coverage-index row, or null when absent
 * @param callable(string): bool $fileExists repository-relative path check
 * @return list<string>
 */
function packageAuditLedgerRepositoryErrors(string $ledgerPath, array $ledger, ?array $indexRow, ?string $record, callable $fileExists): array
{
    $errors = [];
    $package = is_string($ledger['package'] ?? null) ? $ledger['package'] : '';
    $short = (string) preg_replace('#^@?waaseyaa/#', '', $package);
    $recordPath = "docs/audits/packages/$short.md";
    if ($ledgerPath !== "docs/audits/packages/$short.ledger.json") {
        $errors[] = "ledger for $package must live at docs/audits/packages/$short.ledger.json";
    }
    if ($record === null) {
        $errors[] = "$recordPath (the human record) is missing";
    }
    if ($indexRow === null) {
        $errors[] = "coverage index has no row for $package";
    } else {
        $cited = array_map(static fn(mixed $e): mixed => is_array($e) ? ($e['path'] ?? null) : null, (array) ($indexRow['evidence'] ?? []));
        foreach ([$ledgerPath, $recordPath] as $path) {
            if (!in_array($path, $cited, true)) {
                $errors[] = "coverage index row doesn't cite $path";
            }
        }
        if (($indexRow['audit_base'] ?? null) !== ($ledger['base'] ?? null)) {
            $errors[] = 'ledger base differs from the coverage index audit_base';
        }
        if (($indexRow['audit_date'] ?? null) !== ($ledger['audit_date'] ?? null)) {
            $errors[] = 'ledger audit_date differs from the coverage index';
        }
        $lock = $ledger['dependency_identity']['composer_lock_sha256'] ?? '';
        if (($indexRow['dependency_identity'] ?? null) !== 'composer.lock sha256:' . $lock) {
            $errors[] = 'ledger dependency identity differs from the coverage index';
        }
        $assessed = ($ledger['milestone']['assessed'] ?? false) === true;
        if ($assessed !== (($indexRow['audit_state'] ?? null) === 'assessed')) {
            $errors[] = 'ledger milestone.assessed disagrees with the coverage index audit_state';
        }
        foreach ((array) ($ledger['probes'] ?? []) as $probe) {
            $retained = is_array($probe) ? ($probe['retained_path'] ?? null) : null;
            if (is_string($retained) && !in_array($retained, $cited, true)) {
                $errors[] = "coverage index row doesn't cite retained probe $retained";
            }
        }
    }
    foreach ((array) ($ledger['probes'] ?? []) as $probe) {
        $retained = is_array($probe) ? ($probe['retained_path'] ?? null) : null;
        if (is_string($retained) && !$fileExists($retained)) {
            $errors[] = "retained probe $retained is not committed";
        }
    }
    if ($record !== null) {
        foreach ((array) ($ledger['findings'] ?? []) as $finding) {
            if (!is_array($finding) || !is_string($finding['id'] ?? null) || ($finding['merged_into'] ?? null) !== null) {
                continue;
            }
            if (!str_contains($record, $finding['id'])) {
                $errors[] = "the record doesn't mention {$finding['id']}";
            }
            $ownedHere = ($finding['owned_by'] ?? null) === $package;
            $raised = in_array($finding['severity'] ?? null, ['critical', 'high', 'medium'], true);
            if ($ownedHere && $raised && !packageAuditLedgerIsSecurity($finding) && !str_contains($record, '### `' . $finding['id'] . '`')) {
                $errors[] = "the record has no detail block for medium-or-higher {$finding['id']}";
            }
        }
    }

    return $errors;
}

/** @param array<mixed> $finding */
function packageAuditLedgerIsSecurity(array $finding): bool
{
    return ($finding['area'] ?? null) === 'SEC';
}

function packageAuditLedgerIsSha(mixed $value): bool
{
    return is_string($value) && preg_match('#^[0-9a-f]{40}$#', $value) === 1;
}

function packageAuditLedgerIsText(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

function packageAuditLedgerIsOwner(mixed $value): bool
{
    return is_string($value) && preg_match(PACKAGE_AUDIT_LEDGER_OWNER, $value) === 1;
}

function packageAuditLedgerIsTextList(mixed $value, bool $allowEmpty = false): bool
{
    if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
        return false;
    }

    return array_filter($value, static fn(mixed $v): bool => !packageAuditLedgerIsText($v)) === [];
}

/**
 * @param array<mixed> $entries
 * @param callable(string): void $err
 * @return array<string, true>
 */
function packageAuditLedgerIndexIds(array $entries, string $pattern, string $section, callable $err): array
{
    $ids = [];
    foreach ($entries as $i => $entry) {
        $id = is_array($entry) ? ($entry['id'] ?? null) : null;
        if (!is_string($id) || preg_match($pattern, $id) !== 1 || isset($ids[$id])) {
            $err("{$section}[$i] needs a unique id matching $pattern");
            continue;
        }
        $ids[$id] = true;
    }

    return $ids;
}

/**
 * @param array<string, mixed> $findingIds
 * @param array<string, true> $securityIds
 * @param callable(string): void $err
 */
function packageAuditLedgerCheckRefs(mixed $refs, string $where, array $findingIds, array $securityIds, callable $err): void
{
    if (!is_array($refs) || !array_is_list($refs)) {
        $err("$where: findings must be a list");

        return;
    }
    foreach ($refs as $ref) {
        if (!is_string($ref) || !isset($findingIds[$ref])) {
            $err("$where references unknown finding " . (is_string($ref) ? $ref : '?'));
        } elseif (isset($securityIds[$ref])) {
            $err("$where ties security finding $ref to a file or checklist item");
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $ledgerFile = $argv[1] ?? '';
    if ($ledgerFile === '' || !is_file($ledgerFile)) {
        fwrite(STDERR, "usage: php bin/lib/package-audit-ledger.php <ledger.json> [--record=<record.md>] [--index=<coverage-index.json>]\n");
        exit(2);
    }
    $options = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (preg_match('#^--(record|index)=(.+)$#', $arg, $m) === 1) {
            $options[$m[1]] = $m[2];
        }
    }
    try {
        $ledger = json_decode((string) file_get_contents($ledgerFile), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, 'invalid JSON: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $errors = is_array($ledger) ? packageAuditLedgerErrors($ledger) : ['the ledger must be a JSON object'];
    if ($errors === [] && isset($options['index'])) {
        $index = json_decode((string) file_get_contents($options['index']), true, 32, JSON_THROW_ON_ERROR);
        $row = null;
        foreach ($index['packages'] ?? [] as $candidate) {
            if (($candidate['package'] ?? null) === $ledger['package']) {
                $row = $candidate;
            }
        }
        $short = preg_replace('#^@?waaseyaa/#', '', (string) $ledger['package']);
        $record = isset($options['record']) && is_file($options['record']) ? (string) file_get_contents($options['record']) : null;
        $root = dirname($options['index'], 4);
        $errors = packageAuditLedgerRepositoryErrors(
            "docs/audits/packages/$short.ledger.json",
            $ledger,
            $row,
            $record,
            static fn(string $path): bool => is_file($root . '/' . $path),
        );
    }
    foreach ($errors as $error) {
        fwrite(STDOUT, "ERROR $error\n");
    }
    fwrite(STDOUT, $errors === [] ? "OK\n" : count($errors) . " problem(s)\n");
    exit($errors === [] ? 0 : 1);
}
