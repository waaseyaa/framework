<?php

declare(strict_types=1);

/**
 * FW-PACKAGE-CONVERGENCE-01 (#3118): the executable contract for package audit
 * ledgers, docs/audits/packages/<package>.ledger.json.
 *
 * Later audits read ledgers mechanically (their intake is every finding and
 * handoff another ledger routes to them), so a malformed key, type, enum,
 * owner, reference or security row would corrupt them silently. The shape is
 * the one the package-convergence skill's audit record template documents;
 * tests/Architecture/PackageAuditLedgerTest.php runs these checks over every
 * committed ledger and seeds each defect class.
 *
 * Two layers:
 * - packageAuditLedgerErrors(): the document on its own (keys, types, enums,
 *   internal references, security redaction);
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

/*
 * Field types: text is a non-empty string, text? is null or text, string may be
 * empty, list is any JSON array, textlist a non-empty list of text, textlist0 a
 * possibly empty one, object a JSON object, any is checked by its own rule.
 */
const PACKAGE_AUDIT_LEDGER_FINDING = [
    'id' => 'text', 'title' => 'text', 'area' => 'text', 'severity' => 'text', 'confidence' => 'text',
    'evidence_level' => 'text', 'observed' => 'text', 'expected_contract' => 'text', 'consequence' => 'text',
    'refutation' => 'text', 'disposition' => 'text', 'destination' => 'list', 'dependencies' => 'text',
    'acceptance' => 'text', 'residual_risk' => 'text', 'next_action' => 'text', 'discovered_in' => 'text',
    'owned_by' => 'text', 'co_owners' => 'list', 'affected_consumers' => 'any', 'blocks_assessment' => 'bool',
    'decision' => 'text?', 'consumer_unblock' => 'text?', 'merged_into' => 'text?', 'verification' => 'object',
    'probes' => 'any', 'notes' => 'string',
];

const PACKAGE_AUDIT_LEDGER_SECTIONS = [
    'charter' => ['question' => 'text', 'answer' => 'text', 'evidence' => 'text'],
    'roster' => ['file' => 'text', 'role' => 'text', 'classification' => 'text', 'evidence_level' => 'text', 'notes' => 'string', 'findings' => 'list'],
    'checklists' => ['profile' => 'text', 'item' => 'text', 'status' => 'text', 'answer' => 'text', 'evidence' => 'string', 'findings' => 'list'],
    'intake' => ['item' => 'text', 'from' => 'text', 'disposition' => 'text', 'local_finding' => 'text?', 'notes' => 'string'],
    'refuted' => ['id' => 'text', 'lead' => 'text', 'investigation' => 'text', 'refutation' => 'text', 'evidence' => 'text'],
    'handoffs' => ['id' => 'text', 'lead' => 'text', 'owner' => 'text', 'co_owners' => 'list', 'affected_consumers' => 'textlist', 'issue' => 'text?', 'blocks_assessment' => 'bool'],
    'decisions' => ['id' => 'text', 'decision' => 'text', 'findings' => 'list', 'what_would_settle_it' => 'text', 'who_decides' => 'text', 'status' => 'text', 'resolution' => 'text?'],
    'uncertainties' => ['id' => 'text', 'uncertainty' => 'text', 'findings' => 'list', 'what_would_settle_it' => 'text', 'status' => 'text', 'resolution' => 'text?'],
    'issue_reconciliation' => ['issue' => 'text', 'claim' => 'text', 'status' => 'text', 'evidence' => 'text'],
    'remediation_plan' => ['slice' => 'text', 'findings' => 'textlist', 'acceptance' => 'text', 'depends_on' => 'textlist0'],
    'qualification' => ['profile' => 'text', 'supported' => 'text', 'evidence_class' => 'text', 'evidence' => 'text', 'gap_owner' => 'text?'],
    'probes' => ['name' => 'text', 'purpose' => 'text', 'result' => 'text', 'retained_path' => 'text?', 'reproduce' => 'text'],
    'evidence_runs' => ['command' => 'text', 'base' => 'text', 'dependency_identity' => 'text', 'runner' => 'text', 'host' => 'text', 'proves' => 'text', 'result' => 'text'],
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
const PACKAGE_AUDIT_LEDGER_OPEN_STATES = ['open', 'settled'];

const PACKAGE_AUDIT_LEDGER_OWNER = '#^(waaseyaa/[a-z0-9][a-z0-9-]*|external:[A-Za-z0-9][A-Za-z0-9._/-]*)$#';
const PACKAGE_AUDIT_LEDGER_FINDING_ID = '#^([A-Z][A-Z0-9]*)-([A-Z]+)-(\d{3})$#';
const PACKAGE_AUDIT_LEDGER_REFUTED_ID = '#^[A-Z][A-Z0-9]*-R-\d{3}$#';
const PACKAGE_AUDIT_LEDGER_RETAINED_PROBE = '#^tests/Fixtures/Audits/[A-Za-z0-9]+/[A-Z][A-Z0-9]*-[A-Z]+-\d{3}-[a-z0-9-]+\.php$#';
/** A file with a line: `x.php:42`, `x.php#L42`, `x.php line 42`, `x.php (lines 40-44)`. */
const PACKAGE_AUDIT_LEDGER_FILE_LINE = '#[A-Za-z0-9_./-]+\.(?:php|md|js|ts|vue|json|ya?ml|neon|sh)(?::\d+|\#L\d+|,?\s+\(?lines?\s+\d+)#i';
/** A code file, a static member (`Class::method`) or a method call (`->method(`). */
const PACKAGE_AUDIT_LEDGER_CODE_REF = '#[A-Za-z0-9_.-]+\.(?:php|js|ts|vue|neon|sh)\b|\b[A-Za-z_][A-Za-z0-9_]*::[A-Za-z_][A-Za-z0-9_]*|->[A-Za-z_][A-Za-z0-9_]*\(#';

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

    foreach (['findings', ...array_keys(PACKAGE_AUDIT_LEDGER_SECTIONS)] as $section) {
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

    // Every entry of every section has exactly its keys, each of its type; only
    // well-formed entries go on to the semantic checks.
    $valid = [];
    foreach (PACKAGE_AUDIT_LEDGER_SECTIONS as $section => $shape) {
        $valid[$section] = [];
        foreach ($ledger[$section] as $i => $entry) {
            if (packageAuditLedgerCheckShape($entry, $shape, "{$section}[$i]", $err)) {
                $valid[$section][$i] = $entry;
            }
        }
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
    $decisionIds = packageAuditLedgerIndexIds($valid['decisions'], '#^D\d+$#', 'decisions', $err);
    packageAuditLedgerIndexIds($valid['uncertainties'], '#^U\d+$#', 'uncertainties', $err);
    packageAuditLedgerIndexIds($valid['handoffs'], '#^H\d+$#', 'handoffs', $err);
    $openDecisions = [];
    foreach ($valid['decisions'] as $decision) {
        if ($decision['status'] === 'open') {
            $openDecisions[$decision['id']] = true;
        }
    }

    $slices = [];
    foreach ($valid['remediation_plan'] as $i => $slice) {
        if (isset($slices[$slice['slice']])) {
            $err("remediation_plan[$i] needs a unique slice name");
            continue;
        }
        $slices[$slice['slice']] = $slice;
        foreach ($slice['findings'] as $ref) {
            if (!isset($findingIds[$ref])) {
                $err("remediation_plan[{$slice['slice']}] references unknown finding $ref");
            }
        }
    }

    $refutedIds = [];
    foreach ($valid['refuted'] as $i => $lead) {
        if (preg_match(PACKAGE_AUDIT_LEDGER_REFUTED_ID, $lead['id']) !== 1 || isset($refutedIds[$lead['id']])) {
            $err("refuted[$i] needs a unique <PKG>-R-NNN id");
            continue;
        }
        $refutedIds[$lead['id']] = true;
    }

    if (count($ledger['charter']) < 5) {
        $err('charter must answer at least the five charter questions');
    }

    $files = [];
    foreach ($valid['roster'] as $i => $row) {
        if (isset($files[$row['file']])) {
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

    foreach ($valid['checklists'] as $i => $item) {
        if (!in_array($item['status'], PACKAGE_AUDIT_LEDGER_CHECKLIST_STATUSES, true)) {
            $err("checklists[$i]: unknown status");
        }
        packageAuditLedgerCheckRefs($item['findings'], "checklists[$i]", $findingIds, $securityIds, $err);
        if ($item['status'] === 'finding' && $item['findings'] === []) {
            $err("checklists[$i] has status finding but names no finding");
        }
    }

    foreach ($valid['intake'] as $i => $item) {
        if (!in_array($item['disposition'], PACKAGE_AUDIT_LEDGER_INTAKE_DISPOSITIONS, true)) {
            $err("intake[$i]: unknown disposition");
        }
        if ($item['local_finding'] !== null && !isset($findingIds[$item['local_finding']])) {
            $err("intake[$i] references unknown local finding");
        }
    }

    foreach ($findingIds as $id => $finding) {
        packageAuditLedgerCheckFinding((string) $id, $finding, $findingIds, $decisionIds, $openDecisions, $slices, $assessed, $err);
    }
    foreach (array_keys($findingIds) as $id) {
        if (isset($refutedIds[$id])) {
            $err("$id is both a finding and a refuted lead");
        }
    }

    foreach ($valid['handoffs'] as $handoff) {
        if (!packageAuditLedgerIsOwner($handoff['owner'])) {
            $err("handoff {$handoff['id']}: owner must be one package name in owner format");
        }
        if (array_filter($handoff['co_owners'], static fn(mixed $o): bool => !packageAuditLedgerIsOwner($o)) !== []) {
            $err("handoff {$handoff['id']}: co_owners must be owner-format names");
        }
        if ($handoff['blocks_assessment'] !== false) {
            $err("handoff {$handoff['id']}: a lead that blocks the assessment can't be handed off");
        }
    }

    $open = ['decisions' => 0, 'uncertainties' => 0];
    foreach (['decisions', 'uncertainties'] as $section) {
        foreach ($valid[$section] as $entry) {
            packageAuditLedgerCheckRefs($entry['findings'], "$section {$entry['id']}", $findingIds, [], $err);
            if (!in_array($entry['status'], PACKAGE_AUDIT_LEDGER_OPEN_STATES, true)) {
                $err("$section {$entry['id']}: status must be open or settled");
            } elseif ($entry['status'] === 'open' && $entry['resolution'] !== null) {
                $err("$section {$entry['id']}: an open entry has no resolution yet");
            } elseif ($entry['status'] === 'settled' && $entry['resolution'] === null) {
                $err("$section {$entry['id']}: a settled entry needs its resolution");
            }
            if ($entry['status'] === 'open') {
                $open[$section]++;
            }
        }
    }

    foreach ($valid['qualification'] as $i => $entry) {
        $profile = preg_replace('# \(.*\)$#', '', $entry['profile']);
        if (!in_array($profile, PACKAGE_AUDIT_LEDGER_PROFILES, true)) {
            $err("qualification[$i]: unknown installation profile");
        }
        if (!in_array($entry['evidence_class'], PACKAGE_AUDIT_LEDGER_EVIDENCE_CLASSES, true)) {
            $err("qualification[$i]: unknown evidence class");
        }
        if ($entry['evidence_class'] === 'source' && str_starts_with($entry['evidence'], 'qualified')) {
            $err("qualification[$i]: source evidence is never qualified");
        }
        if (str_starts_with($entry['supported'], 'yes') && $entry['evidence_class'] === 'none' && $entry['gap_owner'] === null) {
            $err("qualification[$i]: a supported profile without evidence needs a gap owner");
        }
    }

    foreach ($valid['probes'] as $i => $probe) {
        if ($probe['retained_path'] !== null && preg_match(PACKAGE_AUDIT_LEDGER_RETAINED_PROBE, $probe['retained_path']) !== 1) {
            $err("probes[$i]: retained_path must be tests/Fixtures/Audits/<Package>/<FindingID>-<slug>.php");
        }
    }

    $scorecard = $ledger['scorecard'];
    if (!is_array($scorecard) || $scorecard === [] || array_is_list($scorecard)) {
        $err('scorecard must be a non-empty object');
    } elseif (!is_array($scorecard['open'] ?? null) || !is_int($scorecard['open']['decisions'] ?? null) || !is_int($scorecard['open']['uncertainties'] ?? null)) {
        $err('scorecard.open must give the open decisions and uncertainties as counts');
    } else {
        foreach ($open as $section => $count) {
            if ($scorecard['open'][$section] !== $count) {
                $err("scorecard.open.$section is {$scorecard['open'][$section]} but $count $section are open");
            }
        }
    }

    packageAuditLedgerCheckNeighbours($ledger, $securityIds, $err);

    return $errors;
}

/**
 * @param array<string, mixed> $findingIds
 * @param array<string, true> $decisionIds
 * @param array<string, true> $openDecisions
 * @param array<string, array<mixed>> $slices
 * @param callable(string): void $err
 */
function packageAuditLedgerCheckFinding(string $id, mixed $finding, array $findingIds, array $decisionIds, array $openDecisions, array $slices, bool $assessed, callable $err): void
{
    if (!packageAuditLedgerCheckShape($finding, PACKAGE_AUDIT_LEDGER_FINDING, "finding $id", $err)) {
        return;
    }
    if (preg_match(PACKAGE_AUDIT_LEDGER_FINDING_ID, $id, $m) !== 1) {
        $err("finding $id: id must be <PKG>-<AREA>-NNN");

        return;
    }
    $security = packageAuditLedgerIsSecurity($finding);
    if ($finding['area'] !== $m[2]) {
        $err("finding $id: area must match the id's area segment");
    }
    foreach (['severity' => PACKAGE_AUDIT_LEDGER_SEVERITIES, 'confidence' => PACKAGE_AUDIT_LEDGER_CONFIDENCE, 'evidence_level' => PACKAGE_AUDIT_LEDGER_LEVELS] as $key => $allowed) {
        if (!in_array($finding[$key], $allowed, true)) {
            $err("finding $id: unknown $key");
        }
    }
    if (!packageAuditLedgerIsOwner($finding['discovered_in']) || !packageAuditLedgerIsOwner($finding['owned_by'])) {
        $err("finding $id: discovered_in and owned_by must each be one owner-format name");
    }
    if (array_filter($finding['co_owners'], static fn(mixed $o): bool => !packageAuditLedgerIsOwner($o)) !== []
        || in_array($finding['owned_by'], $finding['co_owners'], true)
    ) {
        $err("finding $id: co_owners must be owner-format names other than owned_by");
    }
    if ($finding['decision'] !== null && !isset($decisionIds[$finding['decision']])) {
        $err("finding $id references unknown decision {$finding['decision']}");
    }
    if ($finding['blocks_assessment'] === true) {
        if ($finding['decision'] === null) {
            $err("finding $id blocks the assessment but names no maintainer decision");
        } elseif ($assessed && isset($openDecisions[$finding['decision']])) {
            $err("finding $id blocks the assessment, so an assessed ledger needs decision {$finding['decision']} settled");
        }
    }
    if ($finding['consumer_unblock'] !== null) {
        $known = array_filter(PACKAGE_AUDIT_LEDGER_CONSUMER_UNBLOCK, static fn(string $v): bool => str_starts_with($finding['consumer_unblock'], $v));
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
    if ($destination === []) {
        $err("finding $id needs at least one destination");
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
    if (packageAuditLedgerCheckShape($verification, ['tier' => 'text', 'performed' => 'text', 'reversals' => 'textlist0'], "finding $id: verification", $err)) {
        if (!in_array($verification['tier'], PACKAGE_AUDIT_LEDGER_TIERS, true)) {
            $err("finding $id: verification.tier must be A, B, C or pending");
        } elseif ($assessed && $verification['tier'] === 'pending') {
            $err("finding $id: an assessed ledger can't hold unverified findings");
        } elseif (($security || in_array($finding['severity'], ['critical', 'high', 'medium'], true)) && !in_array($verification['tier'], ['A', 'pending'], true)) {
            $err("finding $id: security and medium-or-higher findings need tier A");
        }
    }

    if (!$security) {
        if ($finding['severity'] === 'withheld') {
            $err("finding $id: only security findings are withheld");
        }
        if (!packageAuditLedgerIsTextList($finding['affected_consumers'])) {
            $err("finding $id: affected_consumers must be a non-empty list (write \"none\" when none)");
        }
        if (!packageAuditLedgerIsTextList($finding['probes'], allowEmpty: true)) {
            $err("finding $id: probes must be a list of names");
        }

        return;
    }

    if ($finding['severity'] !== 'withheld') {
        $err("security finding $id: public severity must be withheld");
    }
    if ($finding['affected_consumers'] !== 'withheld') {
        $err("security finding $id: affected_consumers must be withheld");
    }
    if ($finding['co_owners'] !== []) {
        $err("security finding $id: co-owners stay in the private brief");
    }
    if (!is_int($finding['probes']) || $finding['probes'] < 0) {
        $err("security finding $id: probes must be a count, not names");
    }
    if (array_filter($destination, static fn(mixed $d): bool => is_array($d) && ($d['kind'] ?? null) === 'private-report') === []) {
        $err("security finding $id: destination must include a private report");
    }
    // Every string in the row, however deeply nested, keys included.
    foreach ($finding as $field => $value) {
        foreach (packageAuditLedgerStrings($value) as $text) {
            if (packageAuditLedgerLocates($text)) {
                $err("security finding $id: $field cites a code location (a file, line or symbol)");
                break;
            }
        }
    }
}

/**
 * Nothing public may tie a security finding to a place in the code: an entry
 * that names a security ID carries no code location anywhere in it. Entries
 * are the rows of the list sections; the free-form parts (milestone, identity,
 * not reviewed, host limits, scorecard) are checked string by string.
 *
 * @param array<mixed> $ledger
 * @param array<string, true> $securityIds
 * @param callable(string): void $err
 */
function packageAuditLedgerCheckNeighbours(array $ledger, array $securityIds, callable $err): void
{
    if ($securityIds === []) {
        return;
    }
    $mention = '#(?<![A-Za-z0-9-])(?:' . implode('|', array_map(static fn(string $id): string => preg_quote($id, '#'), array_keys($securityIds))) . ')(?![0-9])#';
    $check = static function (array $strings, string $where) use ($mention, $err): void {
        if (array_filter($strings, static fn(string $s): bool => preg_match($mention, $s) === 1) === []) {
            return;
        }
        foreach ($strings as $text) {
            if (packageAuditLedgerLocates($text)) {
                $err("$where names a security finding next to a code location");

                return;
            }
        }
    };
    foreach (['findings', ...array_keys(PACKAGE_AUDIT_LEDGER_SECTIONS)] as $section) {
        foreach ($ledger[$section] as $i => $entry) {
            if ($section === 'findings') {
                if (!is_array($entry) || packageAuditLedgerIsSecurity($entry)) {
                    continue;
                }
                $where = 'finding ' . (is_string($entry['id'] ?? null) ? $entry['id'] : "[$i]");
            } else {
                $where = "{$section}[$i]";
            }
            $check(packageAuditLedgerStrings($entry), $where);
        }
    }
    foreach (['milestone', 'dependency_identity', 'not_reviewed', 'host_limits', 'scorecard'] as $section) {
        foreach (packageAuditLedgerStrings($ledger[$section]) as $text) {
            $check([$text], $section);
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
        if ($record !== null) {
            $state = preg_match('#^- \*\*Audit state:\*\* ([a-z ]+?)\.#m', $record, $m) === 1 ? $m[1] : null;
            if ($state !== ($indexRow['audit_state'] ?? null)) {
                $errors[] = sprintf("the record's audit state (%s) disagrees with the coverage index (%s)", $state ?? 'missing', (string) ($indexRow['audit_state'] ?? 'missing'));
            }
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
    if ($record === null) {
        return $errors;
    }

    $securityIds = [];
    foreach ((array) ($ledger['findings'] ?? []) as $finding) {
        if (!is_array($finding) || !is_string($finding['id'] ?? null)) {
            continue;
        }
        if (packageAuditLedgerIsSecurity($finding)) {
            $securityIds[] = $finding['id'];
        }
        if (($finding['merged_into'] ?? null) !== null) {
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

    // The record states the ledger's counts in fixed words, so they can't drift.
    $count = static fn(string $section): int => is_array($ledger[$section] ?? null) ? count($ledger[$section]) : 0;
    $openCount = static fn(string $section): int => count(array_filter(
        is_array($ledger[$section] ?? null) ? $ledger[$section] : [],
        static fn(mixed $e): bool => is_array($e) && ($e['status'] ?? null) === 'open',
    ));
    $openIds = [];
    foreach (['decisions', 'uncertainties'] as $section) {
        foreach (is_array($ledger[$section] ?? null) ? $ledger[$section] : [] as $entry) {
            if (is_array($entry) && ($entry['status'] ?? null) === 'open' && is_string($entry['id'] ?? null)) {
                $openIds[] = $entry['id'];
            }
        }
    }
    if (preg_match('#^- \*\*Structured ledger:\*\*(.*)$#m', $record, $line) !== 1) {
        $errors[] = 'the record has no "Structured ledger" header line';
    } else {
        $claims = [
            ['N findings', '(\d+) findings?', [$count('findings')]],
            ['N refuted leads', '(\d+) refuted leads?', [$count('refuted')]],
            ['N checklist answers', '(\d+) checklist answers?', [$count('checklists')]],
            ['N handoffs', '(\d+) handoffs?', [$count('handoffs')]],
            ['N decisions (N open)', '(\d+) decisions? \((\d+) open\)', [$count('decisions'), $openCount('decisions')]],
            ['N uncertainties (N open)', '(\d+) uncertaint(?:y|ies) \((\d+) open\)', [$count('uncertainties'), $openCount('uncertainties')]],
            ['N probe entries', '(\d+) probe entr(?:y|ies)', [$count('probes')]],
            ['N evidence runs', '(\d+) evidence runs?', [$count('evidence_runs')]],
        ];
        foreach ($claims as [$words, $pattern, $actual]) {
            if (preg_match('#\b' . $pattern . '#', $line[1], $m) !== 1) {
                $errors[] = "the record's Structured ledger line doesn't state \"$words\"";
            } elseif (array_map('intval', array_slice($m, 1)) !== $actual) {
                $errors[] = sprintf("the record's Structured ledger line says \"%s\"; the ledger has %s", $m[0], implode(' and ', $actual));
            }
        }
    }
    if (preg_match('#^- \*\*Open:\*\*(.*)$#m', $record, $line) !== 1) {
        $errors[] = 'the record\'s scorecard has no "Open" line';
    } else {
        foreach (['decisions' => 'decisions?', 'uncertainties' => 'uncertaint(?:y|ies)'] as $section => $noun) {
            if (preg_match('#\b(\d+|no) open ' . $noun . '#', $line[1], $m) !== 1) {
                $errors[] = "the record's Open line doesn't state the open $section";
            } elseif (($m[1] === 'no' ? 0 : (int) $m[1]) !== $openCount($section)) {
                $errors[] = sprintf("the record's Open line says \"%s\"; the ledger has %d open", $m[0], $openCount($section));
            }
        }
    }
    foreach ($openIds as $id) {
        if (!str_contains($record, "| $id |")) {
            $errors[] = "the record's decisions and uncertainties table has no row for open $id";
        }
    }
    // Public text too: a line that names a security finding cites no code.
    if ($securityIds !== []) {
        $mention = '#(?<![A-Za-z0-9-])(?:' . implode('|', array_map(static fn(string $id): string => preg_quote($id, '#'), $securityIds)) . ')(?![0-9])#';
        foreach (explode("\n", $record) as $n => $text) {
            if (preg_match($mention, $text) === 1 && packageAuditLedgerLocates($text)) {
                $errors[] = sprintf('record line %d names a security finding next to a code location', $n + 1);
            }
        }
    }

    return $errors;
}

/**
 * @param array<string, string> $shape
 * @param callable(string): void $err
 */
function packageAuditLedgerCheckShape(mixed $entry, array $shape, string $where, callable $err): bool
{
    if (!is_array($entry) || array_keys($entry) !== array_keys($shape)) {
        $err("$where must have exactly " . implode(', ', array_keys($shape)) . ', in order');

        return false;
    }
    $ok = true;
    foreach ($shape as $key => $type) {
        $problem = packageAuditLedgerTypeProblem($entry[$key], $type);
        if ($problem !== null) {
            $err("$where: $key must be $problem");
            $ok = false;
        }
    }

    return $ok;
}

function packageAuditLedgerTypeProblem(mixed $value, string $type): ?string
{
    $ok = match ($type) {
        'text' => packageAuditLedgerIsText($value),
        'text?' => $value === null || packageAuditLedgerIsText($value),
        'string' => is_string($value),
        'bool' => is_bool($value),
        'list' => is_array($value) && array_is_list($value),
        'textlist' => packageAuditLedgerIsTextList($value),
        'textlist0' => packageAuditLedgerIsTextList($value, allowEmpty: true),
        'object' => is_array($value) && ($value === [] || !array_is_list($value)),
        'any' => true,
    };

    return $ok ? null : match ($type) {
        'text' => 'a non-empty string',
        'text?' => 'null or a non-empty string',
        'string' => 'a string',
        'bool' => 'a boolean',
        'list' => 'a list',
        'textlist' => 'a non-empty list of strings',
        'textlist0' => 'a list of strings',
        default => 'an object',
    };
}

/**
 * Every string in a value, however deeply nested, its object keys included.
 *
 * @return list<string>
 */
function packageAuditLedgerStrings(mixed $value): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (!is_array($value)) {
        return [];
    }
    $strings = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $strings[] = $key;
        }
        array_push($strings, ...packageAuditLedgerStrings($item));
    }

    return $strings;
}

/** Whether text points at a place in the code. */
function packageAuditLedgerLocates(string $text): bool
{
    return preg_match(PACKAGE_AUDIT_LEDGER_FILE_LINE, $text) === 1 || preg_match(PACKAGE_AUDIT_LEDGER_CODE_REF, $text) === 1;
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
