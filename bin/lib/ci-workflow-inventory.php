<?php

declare(strict_types=1);

/**
 * Deterministic expanded inventory of the repository's GitHub Actions
 * workflows (FW-CI-CHECK-ROSTER-AUDIT-01, Task 2; GitHub mirror #3087).
 *
 * `bin/generate-ci-workflow-inventory` composes `.github/workflows/*.yml`
 * into `tools/ci-workflow-inventory.json`: one machine-readable record per
 * workflow, job, matrix leaf, visible context, trigger selector, dependency
 * edge, aggregate predicate, artifact flow, runner, permission grant, and
 * repository-local command. The inventory is derived from the workflow
 * files only — it never reads the hand-authored policy manifest
 * (`tools/ci-check-roster.json`); comparing the two is Task 3.
 *
 * Every derived value is labelled with one of three fact kinds so a reader
 * can tell what was read verbatim, what was expanded from a bounded literal
 * matrix, and what is an unevaluated `${{ ... }}` expression:
 *
 *   literal               — read directly from YAML (names, literal lists).
 *   bounded-expansion     — substituted from a literal matrix (`matrix.<axis>`
 *                           references, cartesian product with documented
 *                           include/exclude semantics, artifact-name families).
 *   unresolved-expression — contains `${{ ... }}` that only the runtime can
 *                           evaluate (`inputs.*`, `github.*`, `needs.*.outputs`,
 *                           `fromJSON(...)` matrices). Preserved verbatim.
 *
 * Plain functions with a `cwi_` prefix. The caller must have loaded the
 * Composer autoloader: YAML parsing uses Symfony Yaml, which is a runtime
 * dependency of the framework (packages/config) and the parser every
 * workflow-reading architecture test already uses.
 */

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class CiWorkflowInventoryFailure extends RuntimeException {}

const CWI_SCHEMA_VERSION = 1;
const CWI_KIND_LITERAL = 'literal';
const CWI_KIND_BOUNDED = 'bounded-expansion';
const CWI_KIND_UNRESOLVED = 'unresolved-expression';

/**
 * Trigger event keys whose configuration carries a filter or selector that
 * the inventory records verbatim. Unknown events are still inventoried; only
 * these keys are lifted into typed selector fields.
 */
const CWI_TRIGGER_FILTER_KEYS = [
    'branches', 'branches-ignore', 'tags', 'tags-ignore', 'paths', 'paths-ignore', 'types', 'workflows',
];

/**
 * Bounded selector classification of a job-level `if` expression. Each entry
 * maps a regex over the normalized expression to a selector kind. The
 * classification names what the expression references; it does not claim to
 * evaluate the expression.
 */
const CWI_IF_CLASSIFIERS = [
    'always' => '/\balways\(\)/',
    'failure' => '/\bfailure\(\)/',
    'success' => '/\bsuccess\(\)/',
    // `cancelled` fires on the negated form too, so `!cancelled()` classifies
    // as both `cancelled` and `not-cancelled`; a bare `cancelled()` carries
    // only the former. The pair is what distinguishes them.
    'cancelled' => '/\bcancelled\(\)/',
    'not-cancelled' => '/![^\S\n]*cancelled\(\)/',
    'event' => '/\bgithub\.event_name\b/',
    'actor' => '/\bgithub\.(actor|triggering_actor)\b|\bgithub\.event\.comment\.user\b|\bgithub\.event\.sender\b/',
    'label' => '/\bgithub\.event\.label\b|\bgithub\.event\.pull_request\.labels\b/',
    'tag-ref' => '/refs\/tags\//',
    'ref' => '/\bgithub\.ref\b/',
    'repository' => '/\bgithub\.repository\b|\bhead\.repo\.full_name\b/',
    'pull-request' => '/\bgithub\.event\.pull_request\b|\bgithub\.event\.issue\.pull_request\b/',
    'comment' => '/\bgithub\.event\.comment\b/',
    'workflow-run' => '/\bgithub\.event\.workflow_run\b/',
    'input' => '/\binputs\.[A-Za-z0-9_\-]+/',
    'dependency-result' => '/\bneeds\.[A-Za-z0-9_\-]+\.result\b/',
    'dependency-output' => '/\bneeds\.[A-Za-z0-9_\-]+\.outputs\b/',
];

/**
 * Repository-local command heads recognised inside `run:` steps — the bin/,
 * tools/, tests/, scripts/, composer, vendor/bin, npm, and npx families. Each
 * match is recorded as a normalized command string so a reader can see which
 * governed scripts a hosted job executes without inferring that the command
 * reproduces the job.
 *
 * Every multi-word pattern separates its words with `[^\S\n]+` rather than
 * `\s+`, so a match can never span a line boundary and invent a command out
 * of two unrelated lines (`echo composer` above `install-something`).
 */
const CWI_LOCAL_COMMAND_PATTERNS = [
    // bin/<script>, reached bare, through `php [-d ...]`, through `./`, or
    // through an explicit `$GITHUB_WORKSPACE/` prefix. The bare lookbehind
    // refuses a `/` so `/usr/bin/env` and `vendor/bin/*` never read as a
    // repository bin script.
    '/(?:\$\{?GITHUB_WORKSPACE\}?\/|(?<![\w\/.-])(?:php[^\S\n]+(?:-d[^\S\n]+\S+[^\S\n]+)*)?(?:\.\/)?)(bin\/[A-Za-z0-9._-]+)/',
    '/(?<![\w\/.-])(composer[^\S\n]+(?!--)[a-z][a-z0-9:-]*)/',
    '/(?<![\w\/.-])(?:php[^\S\n]+(?:-d[^\S\n]+\S+[^\S\n]+)*)?(?:\.\/)?(vendor\/bin\/[A-Za-z0-9._-]+)/',
    // Executable tools/ scripts, normalized to the repository-relative path
    // so a `$GITHUB_WORKSPACE/` or `framework/` prefix does not hide them.
    // Only .sh and .php, so tracked tools/ data files stay out.
    '/(?<![\w.-])(tools\/[A-Za-z0-9._\/-]+\.(?:sh|php))/',
    // tests/ and scripts/ hold repository executables too — the PackagedForm
    // and ReferenceConsumer acceptance checks and the FrankenPHP worker
    // acceptance script. Many are extensionless, so they are recognised only
    // through an explicit interpreter, `./`, or `$GITHUB_WORKSPACE/` prefix …
    '/(?:(?:bash|sh|php|node)[^\S\n]+|\$\{?GITHUB_WORKSPACE\}?\/|(?<![\w\/.-])\.\/)["\']?((?:tests|scripts)\/[A-Za-z0-9._\/-]+)/',
    // … or by standing alone on a run line, which is how
    // `run: tests/ReferenceConsumer/check-reference-consumer` invokes one.
    // The lookbehind refuses a line continued from the one above (PowerShell
    // backtick, shell backslash), where such a path is an argument rather
    // than a command — `tests/Integration/LocalOperator` is a PHPUnit path
    // on a continued line in ci.yml, not an executable.
    '/(?m)(?<![\x60\\\\]\n)^[^\S\n]*((?:tests|scripts)\/[A-Za-z0-9._\/-]+)[^\S\n]*$/',
    '/(?<![\w\/.-])(npm[^\S\n]+(?:run[^\S\n]+[A-Za-z0-9:_-]+|test|ci|audit|install))\b/',
    '/(?<![\w\/.-])(npx[^\S\n]+(?:--[A-Za-z0-9-]+[^\S\n]+)*[A-Za-z0-9@][A-Za-z0-9@\/._-]*)/',
];

/**
 * `local_equivalent.status`. "No recognised command" and "hosted
 * infrastructure" are separate facts: conflating them reads as a claim that a
 * job cannot be reproduced locally when the generator simply does not
 * recognise its entry point.
 */
const CWI_LOCAL_DISCOVERABLE = 'discoverable';
const CWI_LOCAL_PARTIAL = 'partial';
const CWI_LOCAL_HOSTED_SIGNALS_ONLY = 'hosted-signals-only';
const CWI_LOCAL_NO_COMMAND = 'no-recognised-command';

/**
 * `if` classification tokens that stop a job being skipped when a
 * prerequisite does not succeed. `failure()` belongs here — the job runs
 * precisely because a prerequisite failed.
 */
const CWI_NON_PROPAGATING_CONDITIONS = ['always', 'not-cancelled', 'failure'];

/**
 * `if` classification tokens that let a job observe BOTH outcomes of its
 * prerequisites, which is what makes an aggregate gate possible.
 * `failure()` is deliberately absent: a job that runs only on failure cannot
 * gate a successful run.
 */
const CWI_AGGREGATE_CONDITIONS = ['always', 'not-cancelled'];

/**
 * Hosted-only signals: evidence that a job depends on GitHub-hosted identity,
 * secrets, or remote mutation, so no local command can reproduce it whole.
 */
const CWI_HOSTED_SIGNAL_PATTERNS = [
    'secrets' => '/\bsecrets\.[A-Za-z0-9_]+/',
    'github-token' => '/\bgithub\.token\b|\bGITHUB_TOKEN\b/',
    'gh-cli' => '/(?<![\w-])gh\s+(api|pr|release|run|workflow|issue|repo|auth)\b/',
    'github-api' => '/api\.github\.com|(?<![\w-])gh\s+api\b/',
    'packagist-api' => '/packagist\.org/',
    'git-push' => '/\bgit\s+push\b/',
    'webhook' => '/\bwebhookUrl\b|_WEBHOOK_URL\b|discord\.com\/api\/webhooks/',
];

/**
 * Structural role evidence. The role is a mechanical derivation over
 * workflow facts (documented in cwi_job_role()); it is not the policy role
 * vocabulary and the mapping between the two belongs to Task 3.
 */
const CWI_PUBLICATION_SIGNAL_PATTERNS = [
    'gh-release' => '/\bgh\s+release\s+(create|upload|edit)\b/',
    'gh-pr-create' => '/\bgh\s+pr\s+create\b/',
    'git-push' => '/\bgit\s+push\b/',
    'packagist-write-api' => '/packagist\.org\/api\//',
    'webhook' => '/\bwebhookUrl\b|_WEBHOOK_URL\b|discord\.com\/api\/webhooks/',
    // Publishing through a third-party action leaves no run-step text, so
    // the action reference itself is the evidence (split.yml and
    // github-release.yml both create the GitHub Release this way).
    'release-action' => '/(?:softprops\/action-gh-release|actions\/create-release)(?:@|\b)/',
];

const CWI_ORCHESTRATION_SIGNAL_PATTERNS = [
    'gh-pr-merge' => '/\bgh\s+pr\s+merge\b/',
    'gh-workflow-run' => '/\bgh\s+workflow\s+run\b/',
    'workflow-dispatch-api' => '/\/dispatches\b/',
    'governed-auto-merge' => '/bin\/enable-governed-auto-merge\b/',
];

/**
 * Builds the complete inventory document for every `*.yml` / `*.yaml` file
 * directly under `<root>/.github/workflows`. Fail-closed: any unparsable or
 * structurally invalid workflow aborts the whole generation.
 *
 * @return array<string, mixed>
 */
function cwi_build_inventory(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $directory = $root . '/.github/workflows';
    if (!is_dir($directory)) {
        throw new CiWorkflowInventoryFailure("workflow directory not found: {$directory}");
    }

    $files = [];
    foreach (scandir($directory, SCANDIR_SORT_NONE) ?: [] as $entry) {
        if (preg_match('/\.ya?ml$/', $entry) === 1 && is_file($directory . '/' . $entry)) {
            $files[] = $entry;
        }
    }
    if ($files === []) {
        throw new CiWorkflowInventoryFailure("no workflow files found under {$directory}");
    }
    sort($files, SORT_STRING);

    $workflows = [];
    foreach ($files as $file) {
        $contents = file_get_contents($directory . '/' . $file);
        if ($contents === false) {
            throw new CiWorkflowInventoryFailure("unable to read workflow {$file}");
        }
        $workflows[] = cwi_inventory_workflow($file, $contents);
    }

    // Cross-run artifact consumers (download-artifact with run-id) can only
    // be matched against producers once every workflow is known.
    $producersByArtifact = [];
    foreach ($workflows as $workflow) {
        foreach ($workflow['jobs'] as $job) {
            foreach ($job['artifacts']['produces'] as $artifact) {
                foreach ($artifact['expansion'] as $name) {
                    $producersByArtifact[$name][] = $workflow['file'] . '#' . $job['key'];
                }
            }
        }
    }
    ksort($producersByArtifact, SORT_STRING);
    foreach ($workflows as $workflowIndex => $workflow) {
        foreach ($workflow['artifact_flows'] as $flowIndex => $flow) {
            if ($flow['cross_run_consumers'] === []) {
                continue;
            }
            $candidates = [];
            foreach ($producersByArtifact as $name => $producers) {
                if (cwi_artifact_matches($flow['id'], (string) $name)) {
                    array_push($candidates, ...$producers);
                }
            }
            $candidates = array_values(array_unique($candidates));
            sort($candidates, SORT_STRING);
            $workflows[$workflowIndex]['artifact_flows'][$flowIndex]['cross_run_producer_candidates'] = $candidates;
        }
    }

    $jobCount = 0;
    $contextCount = 0;
    $unresolvedCount = 0;
    foreach ($workflows as $workflow) {
        $jobCount += count($workflow['jobs']);
        foreach ($workflow['jobs'] as $job) {
            foreach ($job['contexts'] as $context) {
                if ($context['context'] !== null) {
                    ++$contextCount;
                }
            }
        }
        $unresolvedCount += count($workflow['unresolved_expressions']);
    }

    return [
        'schema_version' => CWI_SCHEMA_VERSION,
        'kind' => 'generated-ci-workflow-inventory',
        'change_record' => 'FW-CI-CHECK-ROSTER-AUDIT-01',
        'generator' => 'bin/generate-ci-workflow-inventory',
        'statement' => 'Generated from the workflow files under .github/workflows. Do not edit by hand; '
            . 'regenerate with `php bin/generate-ci-workflow-inventory --write`. This inventory records '
            . 'workflow structure only and makes no policy or conformance claim.',
        'fact_kinds' => [
            CWI_KIND_LITERAL => 'Read verbatim from workflow YAML.',
            CWI_KIND_BOUNDED => 'Expanded from a literal matrix or literal artifact family; every expanded value is enumerated.',
            CWI_KIND_UNRESOLVED => 'Contains a ${{ ... }} expression that only the GitHub runtime can evaluate; preserved verbatim, never evaluated.',
        ],
        'derivation_rules' => cwi_derivation_rules(),
        'source' => [
            'directory' => '.github/workflows',
            'files' => array_map(static fn(array $workflow): array => [
                'file' => $workflow['file'],
                'sha256' => $workflow['source_sha256'],
            ], $workflows),
        ],
        'summary' => [
            'workflow_count' => count($workflows),
            'job_count' => $jobCount,
            'visible_context_count' => $contextCount,
            'unresolved_expression_count' => $unresolvedCount,
        ],
        'workflows' => $workflows,
    ];
}

/**
 * The bounded assumptions the generator applies. They are emitted into the
 * document so a reader of the inventory can audit them without the source.
 *
 * @return array<string, string>
 */
function cwi_derivation_rules(): array
{
    return [
        'ordering' => 'Workflows sort by file name; jobs sort by job key; needs, needed_by, commands, signals, '
            . 'and artifact flows sort bytewise. Matrix axis order, matrix value order, include/exclude order, '
            . 'and step order are semantic and are preserved as written.',
        'line_endings' => 'Workflow contents are normalized to LF before hashing and parsing so the inventory '
            . 'is identical across checkout line-ending conventions.',
        'visible_context' => 'A job with a literal name yields that name. A name containing only matrix.<path> '
            . 'expressions over a literal matrix yields one bounded context per combination. A job without a '
            . 'name yields the job key, and for a literal matrix GitHub\'s documented default '
            . '"<job key> (<value>, <value>, ...)". GitHub documents that rendering for SCALAR axis values '
            . 'only; this generator extends it by flattening an object axis value into its own values in '
            . 'YAML key order (declaration order, not sorted). The extension is unverified against a live '
            . 'check-run name. Any other expression leaves the context unresolved.',
        'matrix' => 'Combinations are the cartesian product of literal axes in declared order. exclude entries '
            . 'remove any combination they partially match. include entries are then applied with the documented '
            . 'GitHub rule: an entry extends every original combination it can extend without overwriting an '
            . 'original axis value, otherwise it becomes a new combination. An axis, include, exclude, or '
            . 'whole matrix given as an expression makes the expansion unresolved.',
        'unresolved_expressions' => 'The per-workflow unresolved_expressions list names identity-affecting '
            . 'expressions the generator did not expand: job names, matrices, artifact names, and runners. '
            . 'Job-level if expressions are runtime conditions by definition and are classified on each job '
            . 'record instead of being listed there.',
        'if_classification' => 'Job-level if expressions are preserved verbatim (whitespace collapsed, an '
            . 'enclosing ${{ }} wrapper removed for the normalized form) and classified by the contexts and '
            . 'status functions they reference. Classification names references; it never evaluates the expression.',
        'conditions' => 'Two token sets are read off the if classification. NON-PROPAGATING is always, '
            . 'not-cancelled (the classification of !cancelled()), and failure(): a job carrying any of them '
            . 'still starts when a prerequisite does not succeed. AGGREGATE-QUALIFYING is always and '
            . 'not-cancelled only: those observe both outcomes and can therefore gate on prerequisite '
            . 'results, whereas failure() runs solely when something failed and cannot gate a successful run. '
            . 'The if classification carries both cancelled and not-cancelled for !cancelled(), and only '
            . 'cancelled for a bare cancelled(), which is what tells the two apart.',
        'expected_skip' => 'own_condition is true when the job has an if expression that does not contain '
            . 'always() — always() is the only condition that cannot skip the job, since !cancelled() still '
            . 'skips on cancellation and failure() skips a successful run. inherited_from lists transitive '
            . 'prerequisites that have their own condition. propagates_prerequisite_failure is true for every '
            . 'job with needs whose if carries no NON-PROPAGATING token.',
        'structural_role' => 'publication: a step matches a publication signal (gh release, gh pr create, git '
            . 'push, packagist.org write API, webhook, a release-publishing action). orchestration: a step '
            . 'matches an orchestration signal '
            . '(gh pr merge, gh workflow run, /dispatches, bin/enable-governed-auto-merge) and no publication '
            . 'signal. aggregate: has needs and its if carries an AGGREGATE-QUALIFYING token. setup: another job in the same '
            . 'workflow needs it and it declares outputs or uploads an artifact another job in the workflow '
            . 'downloads. Otherwise execution. Precedence: publication, orchestration, aggregate, setup, '
            . 'execution; the role_evidence record keeps every signal so a job that is both an aggregate and a '
            . 'publisher is still visible as both. This is a structural heuristic over workflow text, not the '
            . 'policy role vocabulary. It deliberately collides where the workflow itself is ambiguous: a test '
            . 'matrix whose artifact an aggregate downloads satisfies the setup rule, and a matrix that pushes '
            . 'satisfies the publication rule, so a consumer of this inventory must read role_evidence rather '
            . 'than the single label.',
        'artifacts' => 'actions/upload-artifact and actions/download-artifact steps are recorded by name or '
            . 'pattern. Names containing only matrix.<path> expressions over a literal matrix expand to a bounded '
            . 'family. A download with run-id is a cross-run consumer; its producer candidates are matched by '
            . 'glob over every bounded producer name in the inventory and are candidates only.',
        'local_equivalent' => 'Repository-local commands are the bin/, tools/ (.sh and .php), tests/ and '
            . 'scripts/ (through bash, sh, php, node, ./, $GITHUB_WORKSPACE/, or standing alone on a run '
            . 'line), composer, vendor/bin, npm, and npx command heads found in run steps. A whole-line shell '
            . 'comment becomes a blank line, so prose and commented-out commands are not reported as executed '
            . 'and no pattern can splice two surviving lines together. Four statuses, and "no command found" '
            . 'is never conflated with "hosted infrastructure": discoverable — local commands and no hosted '
            . 'signal; partial — local commands alongside hosted signals (secrets, tokens, gh CLI, GitHub or '
            . 'Packagist API, git push, webhooks); hosted-signals-only — no recognised command but hosted '
            . 'signals present; no-recognised-command — neither, which means the generator did not recognise '
            . 'the entry point, NOT that the job has none. The inventory never claims the local commands '
            . 'reproduce the hosted job.',
        'permissions' => 'effective_permissions reports the job-level grant, else the workflow-level grant, '
            . 'else repository-default, meaning the repository GITHUB_TOKEN default that the workflow text '
            . 'cannot reveal.',
    ];
}

/**
 * Inventories one workflow document.
 *
 * @return array<string, mixed>
 */
function cwi_inventory_workflow(string $file, string $contents): array
{
    $normalized = str_replace("\r\n", "\n", $contents);
    try {
        $document = Yaml::parse($normalized);
    } catch (ParseException $exception) {
        throw new CiWorkflowInventoryFailure("unparsable workflow {$file}: {$exception->getMessage()}");
    }
    if (!is_array($document)) {
        throw new CiWorkflowInventoryFailure("workflow {$file} is not a mapping");
    }

    // YAML 1.1 parsers read the bare `on` key as boolean true; Symfony Yaml
    // keeps the string. Accept both so the inventory does not depend on the
    // parser's scalar resolution.
    $triggers = $document['on'] ?? $document[true] ?? null;
    if ($triggers === null) {
        throw new CiWorkflowInventoryFailure("workflow {$file} declares no `on` triggers");
    }
    $jobs = $document['jobs'] ?? null;
    if (!is_array($jobs) || $jobs === []) {
        throw new CiWorkflowInventoryFailure("workflow {$file} declares no jobs");
    }

    $unresolved = [];
    $jobKeys = array_map('strval', array_keys($jobs));
    sort($jobKeys, SORT_STRING);

    $jobRecords = [];
    foreach ($jobKeys as $key) {
        $job = $jobs[$key];
        if (!is_array($job)) {
            throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} is not a mapping");
        }
        $jobRecords[$key] = cwi_inventory_job($file, $key, $job, $jobKeys, $unresolved);
    }

    // Reverse dependency edges and same-workflow artifact consumption are
    // only known once every job is parsed.
    $neededBy = array_fill_keys($jobKeys, []);
    foreach ($jobRecords as $key => $record) {
        foreach ($record['needs'] as $need) {
            $neededBy[$need][] = $key;
        }
    }
    foreach ($jobRecords as $key => $record) {
        sort($neededBy[$key], SORT_STRING);
        $record['needed_by'] = $neededBy[$key];
        $jobRecords[$key] = $record;
    }

    $workflowPermissions = cwi_permissions($document['permissions'] ?? null);
    $flows = cwi_artifact_flows($jobRecords);

    // Second pass over an immutable snapshot: expected_skip walks the whole
    // dependency graph, so it must not observe records this pass is still
    // completing.
    $snapshot = $jobRecords;
    foreach ($jobRecords as $key => $record) {
        $record['effective_permissions'] = cwi_effective_permissions($record['permissions'], $workflowPermissions);
        $record['expected_skip'] = cwi_expected_skip($key, $snapshot);
        $record['role_evidence']['aggregate_condition_with_needs'] = $record['needs'] !== []
            && cwi_condition_token($record['if'], CWI_AGGREGATE_CONDITIONS) !== null;
        $record['role_evidence']['feeds_same_workflow_consumer'] = cwi_feeds_same_workflow_consumer($key, $flows);
        $record['structural_role'] = cwi_job_role($record);
        $record['aggregate'] = cwi_aggregate_record($record);
        $jobRecords[$key] = $record;
    }

    $contexts = [];
    foreach ($jobRecords as $record) {
        foreach ($record['contexts'] as $context) {
            if ($context['context'] !== null) {
                $contexts[$context['context']][] = $record['key'];
            }
        }
    }
    $duplicateContexts = [];
    foreach ($contexts as $context => $owners) {
        if (count($owners) > 1) {
            $duplicateContexts[] = ['context' => (string) $context, 'jobs' => $owners];
        }
    }
    usort($duplicateContexts, static fn(array $a, array $b): int => strcmp($a['context'], $b['context']));

    $unresolved = array_values(array_unique($unresolved));
    sort($unresolved, SORT_STRING);

    return [
        'file' => $file,
        'name' => is_string($document['name'] ?? null) ? $document['name'] : null,
        'source_sha256' => hash('sha256', $normalized),
        'triggers' => cwi_triggers($file, $triggers),
        'permissions' => $workflowPermissions,
        'concurrency' => cwi_scalar_or_expression($document['concurrency'] ?? null),
        'defaults' => $document['defaults'] ?? null,
        'env' => cwi_sorted_keys($document['env'] ?? null),
        'jobs' => array_values($jobRecords),
        'artifact_flows' => $flows,
        'duplicate_contexts' => $duplicateContexts,
        'unresolved_expressions' => $unresolved,
    ];
}

/**
 * Normalizes the `on:` block into an event list plus per-event selectors.
 *
 * @return array<string, mixed>
 */
function cwi_triggers(string $file, mixed $triggers): array
{
    if (is_string($triggers)) {
        $triggers = [$triggers => null];
    } elseif (is_array($triggers) && array_is_list($triggers)) {
        $triggers = array_fill_keys(array_map('strval', $triggers), null);
    } elseif (!is_array($triggers)) {
        throw new CiWorkflowInventoryFailure("workflow {$file} has an unsupported `on` shape");
    }

    $events = array_map('strval', array_keys($triggers));
    sort($events, SORT_STRING);

    $selectors = [];
    foreach ($events as $event) {
        $config = $triggers[$event];
        $entry = ['event' => $event];
        if (is_array($config)) {
            foreach (CWI_TRIGGER_FILTER_KEYS as $filter) {
                if (array_key_exists($filter, $config)) {
                    $entry[str_replace('-', '_', $filter)] = cwi_string_list($config[$filter]);
                }
            }
            if ($event === 'schedule') {
                $entry['cron'] = array_values(array_map(
                    static fn(mixed $item): ?string => is_array($item) && is_string($item['cron'] ?? null) ? $item['cron'] : null,
                    $config,
                ));
            } else {
                if (isset($config['inputs']) && is_array($config['inputs'])) {
                    $inputs = [];
                    foreach ($config['inputs'] as $name => $spec) {
                        $inputs[] = [
                            'name' => (string) $name,
                            'required' => is_array($spec) && (bool) ($spec['required'] ?? false),
                            'type' => is_array($spec) && is_string($spec['type'] ?? null) ? $spec['type'] : null,
                            'has_default' => is_array($spec) && array_key_exists('default', $spec),
                        ];
                    }
                    usort($inputs, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
                    $entry['inputs'] = $inputs;
                }
                $known = array_merge(CWI_TRIGGER_FILTER_KEYS, ['inputs']);
                $other = array_values(array_diff(array_map('strval', array_keys($config)), $known));
                if ($other !== []) {
                    sort($other, SORT_STRING);
                    $entry['other_keys'] = $other;
                }
            }
        }
        $selectors[] = $entry;
    }

    return ['events' => $events, 'selectors' => $selectors];
}

/**
 * Inventories one job. `$unresolved` collects every identity-affecting
 * expression the job carries that the generator does not expand.
 *
 * @param array<string, mixed> $job
 * @param list<string> $jobKeys
 * @param list<string> $unresolved
 * @return array<string, mixed>
 */
function cwi_inventory_job(string $file, string $key, array $job, array $jobKeys, array &$unresolved): array
{
    $reusable = is_string($job['uses'] ?? null) ? $job['uses'] : null;
    if ($reusable === null && !array_key_exists('runs-on', $job)) {
        throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} declares neither runs-on nor uses");
    }

    // A repeated prerequisite is one edge, not two: GitHub deduplicates it
    // and a doubled `needed_by` would misreport the dependency graph.
    $needs = array_values(array_unique(cwi_string_list($job['needs'] ?? [])));
    foreach ($needs as $need) {
        if (!in_array($need, $jobKeys, true)) {
            throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} needs unknown job {$need}");
        }
    }
    sort($needs, SORT_STRING);

    $strategy = cwi_strategy($file, $key, $job['strategy'] ?? null);
    $matrix = cwi_matrix($file, $key, $job['strategy'] ?? null, $unresolved);
    $name = cwi_job_name($key, $job['name'] ?? null, $matrix, $unresolved);

    $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
    $runText = cwi_run_text($steps);
    $jobText = cwi_job_text($job);

    $localCommands = cwi_local_commands($runText);
    $hostedSignals = cwi_pattern_hits(CWI_HOSTED_SIGNAL_PATTERNS, $jobText);

    // Job-level `if` expressions are runtime conditions by definition; they
    // are classified on the job record rather than listed as identity-
    // affecting unresolved expressions.
    $ifRecord = cwi_if_record($job['if'] ?? null);

    // A job-level `uses:` is a literal reusable-workflow reference carried on
    // the job record; it is not an unexpanded expression.
    $runsOn = cwi_scalar_or_expression($job['runs-on'] ?? null);
    $timeout = cwi_scalar_or_expression($job['timeout-minutes'] ?? null);
    foreach (['runs-on' => $runsOn, 'timeout-minutes' => $timeout] as $field => $record) {
        if ($record !== null && $record['kind'] === CWI_KIND_UNRESOLVED) {
            $unresolved[] = 'jobs.' . $key . '.' . $field . ': '
                . json_encode($record['value'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
    }

    return [
        'key' => $key,
        'name' => $name['name'],
        'contexts' => $name['contexts'],
        'structural_role' => null,
        'role_evidence' => [
            'publication_signals' => cwi_pattern_hits(CWI_PUBLICATION_SIGNAL_PATTERNS, $jobText),
            'orchestration_signals' => cwi_pattern_hits(CWI_ORCHESTRATION_SIGNAL_PATTERNS, $jobText),
            'aggregate_condition_with_needs' => false,
            'feeds_same_workflow_consumer' => false,
        ],
        'expansion' => $matrix === null ? 'singleton' : 'matrix',
        'strategy' => $strategy,
        'matrix' => $matrix,
        'reusable_workflow' => $reusable,
        'runs_on' => $runsOn,
        'timeout_minutes' => $timeout,
        'continue_on_error' => $job['continue-on-error'] ?? null,
        'environment' => $job['environment'] ?? null,
        // Verbatim: `defaults.run.working-directory` is what makes an
        // otherwise root-relative run command (`npm ci`) resolve elsewhere, so
        // a reader of local_equivalent needs it.
        'defaults' => $job['defaults'] ?? null,
        'permissions' => cwi_permissions($job['permissions'] ?? null),
        'effective_permissions' => null,
        'needs' => $needs,
        'needed_by' => [],
        'if' => $ifRecord,
        'expected_skip' => null,
        'aggregate' => null,
        'dependency_result_references' => cwi_dependency_result_references($job, $steps),
        'outputs' => cwi_sorted_keys($job['outputs'] ?? null),
        'artifacts' => cwi_job_artifacts($key, $steps, $matrix, $unresolved),
        'action_uses' => cwi_action_uses($steps),
        'step_count' => count($steps),
        'local_equivalent' => [
            'status' => cwi_local_status($localCommands, $hostedSignals),
            'commands' => $localCommands,
            'hosted_signals' => $hostedSignals,
        ],
    ];
}

/**
 * Resolves the job name and its visible contexts.
 *
 * @param array<string, mixed>|null $matrix
 * @param list<string> $unresolved
 * @return array{name: array<string, mixed>, contexts: list<array<string, mixed>>}
 */
function cwi_job_name(string $key, mixed $rawName, ?array $matrix, array &$unresolved): array
{
    $combinations = $matrix !== null && $matrix['resolution'] === CWI_KIND_LITERAL ? $matrix['combinations'] : null;

    if ($rawName === null) {
        if ($matrix === null) {
            return [
                'name' => ['kind' => CWI_KIND_LITERAL, 'raw' => null, 'derivation' => 'default-job-key'],
                'contexts' => [['context' => $key, 'derivation' => 'default-job-key', 'matrix' => null]],
            ];
        }
        if ($combinations === null) {
            $unresolved[] = 'jobs.' . $key . '.name: default name over an unresolved matrix';

            return [
                'name' => ['kind' => CWI_KIND_UNRESOLVED, 'raw' => null, 'derivation' => 'default-matrix-unresolved'],
                'contexts' => [['context' => null, 'derivation' => 'default-matrix-unresolved', 'matrix' => null]],
            ];
        }
        $contexts = [];
        foreach ($combinations as $combination) {
            $contexts[] = [
                'context' => $key . ' (' . implode(', ', cwi_flatten_values($combination)) . ')',
                'derivation' => 'default-matrix',
                'matrix' => $combination,
            ];
        }

        return [
            'name' => ['kind' => CWI_KIND_BOUNDED, 'raw' => null, 'derivation' => 'default-matrix'],
            'contexts' => $contexts,
        ];
    }

    if (!is_string($rawName)) {
        throw new CiWorkflowInventoryFailure("job {$key} has a non-string name");
    }

    if (!cwi_has_expression($rawName)) {
        return [
            'name' => ['kind' => CWI_KIND_LITERAL, 'raw' => $rawName, 'derivation' => 'literal'],
            'contexts' => [['context' => $rawName, 'derivation' => 'literal', 'matrix' => null]],
        ];
    }

    if ($combinations !== null) {
        $contexts = [];
        $allResolved = true;
        foreach ($combinations as $combination) {
            $rendered = cwi_substitute_matrix($rawName, $combination);
            if ($rendered === null) {
                $allResolved = false;
                break;
            }
            $contexts[] = ['context' => $rendered, 'derivation' => 'template-expanded', 'matrix' => $combination];
        }
        if ($allResolved) {
            return [
                'name' => ['kind' => CWI_KIND_BOUNDED, 'raw' => $rawName, 'derivation' => 'template-expanded'],
                'contexts' => $contexts,
            ];
        }
    }

    $unresolved[] = 'jobs.' . $key . '.name: ' . $rawName;

    return [
        'name' => ['kind' => CWI_KIND_UNRESOLVED, 'raw' => $rawName, 'derivation' => 'unresolved-expression'],
        'contexts' => [['context' => null, 'derivation' => 'unresolved-expression', 'matrix' => null]],
    ];
}

/**
 * The job's `strategy` block minus the matrix itself, so a matrix-less
 * `strategy: { fail-fast: false }` is still visible. Null when the job
 * declares no strategy.
 *
 * @return array<string, mixed>|null
 */
function cwi_strategy(string $file, string $key, mixed $strategy): ?array
{
    if ($strategy === null) {
        return null;
    }
    if (!is_array($strategy)) {
        throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} strategy is not a mapping");
    }
    $keys = array_map('strval', array_keys($strategy));
    sort($keys, SORT_STRING);

    return [
        'keys' => $keys,
        'fail_fast' => $strategy['fail-fast'] ?? null,
        'max_parallel' => $strategy['max-parallel'] ?? null,
    ];
}

/**
 * Expands `strategy.matrix` with the documented include/exclude semantics.
 * Returns null when the job has no strategy, or a strategy without a matrix.
 *
 * @param list<string> $unresolved
 * @return array<string, mixed>|null
 */
function cwi_matrix(string $file, string $key, mixed $strategy, array &$unresolved): ?array
{
    // `strategy: { fail-fast: false }` with no matrix is a legal GitHub shape;
    // it simply does not expand the job. cwi_strategy() records its keys.
    if (!is_array($strategy) || !array_key_exists('matrix', $strategy)) {
        return null;
    }
    $matrix = $strategy['matrix'];
    $record = [
        'resolution' => CWI_KIND_LITERAL,
        'raw_expression' => null,
        'axes' => [],
        'expression_axes' => [],
        'include' => null,
        'exclude' => null,
        'combinations' => [],
    ];

    if (is_string($matrix)) {
        if (!cwi_has_expression($matrix)) {
            throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix is a non-expression string");
        }
        $record['resolution'] = CWI_KIND_UNRESOLVED;
        $record['raw_expression'] = $matrix;
        $record['combinations'] = null;
        $unresolved[] = 'jobs.' . $key . '.strategy.matrix: ' . $matrix;

        return $record;
    }
    if (!is_array($matrix)) {
        throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix is neither a mapping nor an expression");
    }

    $axes = [];
    $unresolvedParts = [];
    foreach ($matrix as $axis => $values) {
        $axis = (string) $axis;
        if ($axis === 'include' || $axis === 'exclude') {
            if (is_string($values)) {
                if (!cwi_has_expression($values)) {
                    throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix {$axis} is a non-expression string");
                }
                $record[$axis] = $values;
                $unresolvedParts[] = 'jobs.' . $key . '.strategy.matrix.' . $axis . ': ' . $values;
                continue;
            }
            if (!is_array($values) || !array_is_list($values)) {
                throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix {$axis} must be a list");
            }
            foreach ($values as $entry) {
                if (!is_array($entry) || array_is_list($entry)) {
                    throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix {$axis} entries must be mappings");
                }
            }
            $record[$axis] = $values;
            continue;
        }
        if (is_string($values)) {
            if (!cwi_has_expression($values)) {
                throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix axis {$axis} is a non-expression string");
            }
            $record['expression_axes'][$axis] = $values;
            $unresolvedParts[] = 'jobs.' . $key . '.strategy.matrix.' . $axis . ': ' . $values;
            continue;
        }
        if (!is_array($values) || !array_is_list($values) || $values === []) {
            throw new CiWorkflowInventoryFailure("workflow {$file} job {$key} matrix axis {$axis} must be a non-empty list");
        }
        $axes[$axis] = $values;
    }
    $record['axes'] = $axes;

    if ($unresolvedParts !== []) {
        $record['resolution'] = CWI_KIND_UNRESOLVED;
        $record['combinations'] = null;
        array_push($unresolved, ...$unresolvedParts);

        return $record;
    }

    $record['combinations'] = cwi_expand_matrix(
        $axes,
        is_array($record['include']) ? $record['include'] : [],
        is_array($record['exclude']) ? $record['exclude'] : [],
    );

    return $record;
}

/**
 * Cartesian product, then exclude (partial match removes), then include with
 * the documented GitHub rule. Pure and deterministic.
 *
 * @param array<string, list<mixed>> $axes
 * @param list<array<string, mixed>> $include
 * @param list<array<string, mixed>> $exclude
 * @return list<array<string, mixed>>
 */
function cwi_expand_matrix(array $axes, array $include, array $exclude): array
{
    $combinations = $axes === [] ? [] : [[]];
    foreach ($axes as $axis => $values) {
        $next = [];
        foreach ($combinations as $combination) {
            foreach ($values as $value) {
                $next[] = $combination + [$axis => $value];
            }
        }
        $combinations = $next;
    }

    $combinations = array_values(array_filter(
        $combinations,
        static function (array $combination) use ($exclude): bool {
            foreach ($exclude as $entry) {
                $matches = true;
                foreach ($entry as $axis => $value) {
                    if (!array_key_exists($axis, $combination) || $combination[$axis] !== $value) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    return false;
                }
            }

            return true;
        },
    ));

    $originalCount = count($combinations);
    foreach ($include as $entry) {
        $added = false;
        for ($index = 0; $index < $originalCount; ++$index) {
            $conflict = false;
            foreach ($entry as $axis => $value) {
                if (array_key_exists($axis, $axes) && ($combinations[$index][$axis] ?? null) !== $value) {
                    $conflict = true;
                    break;
                }
            }
            if ($conflict) {
                continue;
            }
            foreach ($entry as $axis => $value) {
                $combinations[$index][$axis] = $value;
            }
            $added = true;
        }
        if (!$added) {
            $combinations[] = $entry;
        }
    }

    return $combinations;
}

/**
 * Substitutes every `${{ matrix.<path> }}` in a template from one literal
 * combination. Returns null when the template carries any other expression
 * or a matrix path the combination does not define.
 *
 * @param array<string, mixed> $combination
 */
function cwi_substitute_matrix(string $template, array $combination): ?string
{
    $failed = false;
    $rendered = preg_replace_callback(
        '/\$\{\{\s*(.*?)\s*\}\}/s',
        static function (array $match) use ($combination, &$failed): string {
            if (preg_match('/^matrix((?:\.[A-Za-z0-9_\-]+)+)$/', $match[1], $path) !== 1) {
                $failed = true;

                return '';
            }
            $value = $combination;
            foreach (explode('.', ltrim($path[1], '.')) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $failed = true;

                    return '';
                }
                $value = $value[$segment];
            }
            if (is_array($value) || $value === null) {
                $failed = true;

                return '';
            }

            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        },
        $template,
    );

    return $failed || $rendered === null ? null : $rendered;
}

/**
 * Flattens a combination's values in key order for the default matrix name.
 *
 * @param array<string, mixed> $combination
 * @return list<string>
 */
function cwi_flatten_values(array $combination): array
{
    $values = [];
    foreach ($combination as $value) {
        if (is_array($value)) {
            array_push($values, ...cwi_flatten_values($value));
        } elseif (is_bool($value)) {
            $values[] = $value ? 'true' : 'false';
        } else {
            $values[] = (string) $value;
        }
    }

    return $values;
}

/**
 * @return array<string, mixed>|null
 */
function cwi_if_record(mixed $condition): ?array
{
    if ($condition === null) {
        return null;
    }
    if (is_bool($condition)) {
        $condition = $condition ? 'true' : 'false';
    }
    if (!is_string($condition)) {
        throw new CiWorkflowInventoryFailure('job if condition must be a string');
    }
    $raw = $condition;
    $normalized = trim((string) preg_replace('/\s+/', ' ', $condition));
    if (preg_match('/^\$\{\{\s*(.*?)\s*\}\}$/s', $normalized, $match) === 1 && !str_contains($match[1], '${{')) {
        $normalized = $match[1];
    }
    $classification = [];
    foreach (CWI_IF_CLASSIFIERS as $kind => $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            $classification[] = $kind;
        }
    }
    sort($classification, SORT_STRING);
    preg_match_all('/\'((?:[^\']|\'\')*)\'/', $normalized, $literals);
    $literalValues = array_values(array_unique($literals[1]));
    sort($literalValues, SORT_STRING);
    preg_match_all('/\b(?:github|needs|inputs|matrix|env|vars|secrets|steps|job|runner)(?:\.[A-Za-z0-9_\-]+)+/', $normalized, $references);
    $referenceValues = array_values(array_unique($references[0]));
    sort($referenceValues, SORT_STRING);

    return [
        'kind' => CWI_KIND_UNRESOLVED,
        'raw' => $raw,
        'normalized' => $normalized,
        'classification' => $classification,
        'references' => $referenceValues,
        'literals' => $literalValues,
    ];
}

/**
 * Every `needs.<job>.result` reference in the job, with where it appears.
 *
 * @param array<string, mixed> $job
 * @param list<mixed> $steps
 * @return list<array{job: string, locations: list<string>}>
 */
function cwi_dependency_result_references(array $job, array $steps): array
{
    $found = [];
    $collect = static function (mixed $text, string $location) use (&$found): void {
        if (!is_string($text)) {
            return;
        }
        if (preg_match_all('/\bneeds\.([A-Za-z0-9_\-]+)\.result\b/', $text, $matches) > 0) {
            foreach ($matches[1] as $dependency) {
                $found[$dependency][$location] = true;
            }
        }
    };
    $collect($job['if'] ?? null, 'if');
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $collect($step['if'] ?? null, 'steps.if');
        $collect($step['run'] ?? null, 'steps.run');
        foreach (is_array($step['env'] ?? null) ? $step['env'] : [] as $value) {
            $collect($value, 'steps.env');
        }
        foreach (is_array($step['with'] ?? null) ? $step['with'] : [] as $value) {
            $collect($value, 'steps.with');
        }
    }
    foreach (is_array($job['env'] ?? null) ? $job['env'] : [] as $value) {
        $collect($value, 'env');
    }
    ksort($found, SORT_STRING);
    $records = [];
    foreach ($found as $dependency => $locations) {
        $locationList = array_keys($locations);
        sort($locationList, SORT_STRING);
        $records[] = ['job' => (string) $dependency, 'locations' => $locationList];
    }

    return $records;
}

/**
 * Artifact steps of a job with bounded expansion of matrix-derived names.
 *
 * @param list<mixed> $steps
 * @param array<string, mixed>|null $matrix
 * @param list<string> $unresolved
 * @return array{produces: list<array<string, mixed>>, consumes: list<array<string, mixed>>}
 */
function cwi_job_artifacts(string $key, array $steps, ?array $matrix, array &$unresolved): array
{
    $combinations = $matrix !== null && $matrix['resolution'] === CWI_KIND_LITERAL ? $matrix['combinations'] : null;
    $produces = [];
    $consumes = [];
    foreach ($steps as $index => $step) {
        if (!is_array($step) || !is_string($step['uses'] ?? null)) {
            continue;
        }
        $uses = $step['uses'];
        $with = is_array($step['with'] ?? null) ? $step['with'] : [];
        if (preg_match('#^actions/upload-artifact(?:@|$)#', $uses) === 1) {
            // upload-artifact's documented default name is "artifact".
            $name = is_string($with['name'] ?? null) ? $with['name'] : 'artifact';
            $produces[] = [
                'step_index' => $index,
                'name' => $name,
                'kind' => cwi_name_kind($name, $combinations),
                'expansion' => cwi_expand_name($key, 'steps.' . $index . '.with.name', $name, $combinations, $unresolved),
                'path' => cwi_string_list($with['path'] ?? null),
                'if' => is_string($step['if'] ?? null) ? $step['if'] : null,
                'if_no_files_found' => $with['if-no-files-found'] ?? null,
                'retention_days' => $with['retention-days'] ?? null,
                'overwrite' => $with['overwrite'] ?? null,
            ];
            continue;
        }
        if (preg_match('#^actions/download-artifact(?:@|$)#', $uses) === 1) {
            $name = is_string($with['name'] ?? null) ? $with['name'] : null;
            $pattern = is_string($with['pattern'] ?? null) ? $with['pattern'] : null;
            $selector = $name ?? $pattern;
            $consumes[] = [
                'step_index' => $index,
                'name' => $name,
                'pattern' => $pattern,
                'kind' => $selector === null ? CWI_KIND_LITERAL : cwi_name_kind($selector, $combinations),
                'expansion' => $selector === null
                    ? []
                    : cwi_expand_name($key, 'steps.' . $index . '.with.' . ($name !== null ? 'name' : 'pattern'), $selector, $combinations, $unresolved),
                'path' => is_string($with['path'] ?? null) ? $with['path'] : null,
                'merge_multiple' => $with['merge-multiple'] ?? null,
                'cross_run' => array_key_exists('run-id', $with),
                'run_id' => is_scalar($with['run-id'] ?? null) ? (string) $with['run-id'] : null,
                'if' => is_string($step['if'] ?? null) ? $step['if'] : null,
            ];
        }
    }

    return ['produces' => $produces, 'consumes' => $consumes];
}

/**
 * @param list<array<string, mixed>>|null $combinations
 */
function cwi_name_kind(string $name, ?array $combinations): string
{
    if (!cwi_has_expression($name)) {
        return CWI_KIND_LITERAL;
    }
    if ($combinations === null) {
        return CWI_KIND_UNRESOLVED;
    }
    foreach ($combinations as $combination) {
        if (cwi_substitute_matrix($name, $combination) === null) {
            return CWI_KIND_UNRESOLVED;
        }
    }

    return CWI_KIND_BOUNDED;
}

/**
 * Bounded expansion of a name template; unresolved templates yield an empty
 * list and are reported.
 *
 * @param list<array<string, mixed>>|null $combinations
 * @param list<string> $unresolved
 * @return list<string>
 */
function cwi_expand_name(string $key, string $location, string $name, ?array $combinations, array &$unresolved): array
{
    $kind = cwi_name_kind($name, $combinations);
    if ($kind === CWI_KIND_LITERAL) {
        return [$name];
    }
    if ($kind === CWI_KIND_UNRESOLVED) {
        $unresolved[] = 'jobs.' . $key . '.' . $location . ': ' . $name;

        return [];
    }
    $names = [];
    foreach ($combinations ?? [] as $combination) {
        $names[] = (string) cwi_substitute_matrix($name, $combination);
    }

    return array_values(array_unique($names));
}

/**
 * Same-workflow artifact flows: every produced or consumed artifact identity
 * with its producers and consumers. Consumer selectors are glob-matched
 * against bounded producer names.
 *
 * @param array<string, array<string, mixed>> $jobs
 * @return list<array<string, mixed>>
 */
function cwi_artifact_flows(array $jobs): array
{
    $flows = [];
    $touch = static function (string $id) use (&$flows): void {
        $flows[$id] ??= [
            'id' => $id,
            'producers' => [],
            'consumers' => [],
            'cross_run_consumers' => [],
            'cross_run_producer_candidates' => [],
        ];
    };
    foreach ($jobs as $key => $job) {
        foreach ($job['artifacts']['produces'] as $artifact) {
            $touch($artifact['name']);
            $flows[$artifact['name']]['producers'][] = [
                'job' => $key,
                'kind' => $artifact['kind'],
                'expansion' => $artifact['expansion'],
                'if' => $artifact['if'],
            ];
        }
    }
    foreach ($jobs as $key => $job) {
        foreach ($job['artifacts']['consumes'] as $artifact) {
            $id = $artifact['name'] ?? $artifact['pattern'] ?? 'artifact';
            $touch($id);
            $matched = [];
            foreach ($flows as $flow) {
                foreach ($flow['producers'] as $producer) {
                    foreach ($producer['expansion'] as $producedName) {
                        if (cwi_artifact_matches($id, $producedName)) {
                            $matched[] = $producer['job'];
                        }
                    }
                }
            }
            $matched = array_values(array_unique($matched));
            sort($matched, SORT_STRING);
            $record = [
                'job' => $key,
                'via' => $artifact['name'] !== null ? 'name' : 'pattern',
                'kind' => $artifact['kind'],
                'matched_producers' => $matched,
                'if' => $artifact['if'],
            ];
            if ($artifact['cross_run']) {
                $record['run_id'] = $artifact['run_id'];
                $flows[$id]['cross_run_consumers'][] = $record;
            } else {
                $flows[$id]['consumers'][] = $record;
            }
        }
    }
    ksort($flows, SORT_STRING);

    return array_values($flows);
}

/**
 * Glob match of an artifact selector (`*` only, as download-artifact
 * patterns use) against one concrete produced name.
 */
function cwi_artifact_matches(string $selector, string $name): bool
{
    if (!str_contains($selector, '*')) {
        return $selector === $name;
    }
    $regex = '/^' . str_replace('\*', '.*', preg_quote($selector, '/')) . '$/';

    return preg_match($regex, $name) === 1;
}

/**
 * The first token from `$tokens` that the job's `if` classification carries,
 * or null when it carries none (including when there is no `if`).
 *
 * @param array<string, mixed>|null $ifRecord
 * @param list<string> $tokens
 */
function cwi_condition_token(?array $ifRecord, array $tokens): ?string
{
    if ($ifRecord === null) {
        return null;
    }
    foreach ($tokens as $token) {
        if (in_array($token, $ifRecord['classification'], true)) {
            return $token;
        }
    }

    return null;
}

/**
 * @param list<string> $commands
 * @param list<string> $hostedSignals
 */
function cwi_local_status(array $commands, array $hostedSignals): string
{
    if ($commands !== []) {
        return $hostedSignals === [] ? CWI_LOCAL_DISCOVERABLE : CWI_LOCAL_PARTIAL;
    }

    return $hostedSignals === [] ? CWI_LOCAL_NO_COMMAND : CWI_LOCAL_HOSTED_SIGNALS_ONLY;
}

/**
 * @param array<string, array<string, mixed>> $jobs
 * @return array{own_condition: bool, inherited_from: list<string>, propagates_prerequisite_failure: bool}
 */
function cwi_expected_skip(string $key, array $jobs): array
{
    // `always()` is the only condition that cannot skip the job at all;
    // `!cancelled()` still skips on cancellation and `failure()` skips on a
    // successful run, so both are own conditions.
    $ownCondition = static fn(array $job): bool => $job['if'] !== null
        && !in_array('always', $job['if']['classification'], true);

    $inherited = [];
    $seen = [$key => true];
    $queue = $jobs[$key]['needs'];
    while ($queue !== []) {
        $need = array_shift($queue);
        if (isset($seen[$need])) {
            continue;
        }
        $seen[$need] = true;
        if ($ownCondition($jobs[$need])) {
            $inherited[] = $need;
        }
        array_push($queue, ...$jobs[$need]['needs']);
    }
    sort($inherited, SORT_STRING);

    return [
        'own_condition' => $ownCondition($jobs[$key]),
        'inherited_from' => $inherited,
        'propagates_prerequisite_failure' => $jobs[$key]['needs'] !== []
            && cwi_condition_token($jobs[$key]['if'], CWI_NON_PROPAGATING_CONDITIONS) === null,
    ];
}

/**
 * Whether a job uploads an artifact that another job in the same workflow
 * downloads. Consumer `matched_producers` is the authority rather than the
 * flow's own producer list, because a pattern consumer
 * (`pattern: php-test-shard-*`) is filed under its own selector while the
 * producers it matches are filed under their bounded names.
 *
 * @param list<array<string, mixed>> $flows
 */
function cwi_feeds_same_workflow_consumer(string $key, array $flows): bool
{
    foreach ($flows as $flow) {
        foreach ($flow['consumers'] as $consumer) {
            if ($consumer['job'] !== $key && in_array($key, $consumer['matched_producers'], true)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Structural role: a mechanical derivation over the job's own evidence
 * (see cwi_derivation_rules()['structural_role']). Precedence: publication,
 * orchestration, aggregate, setup, execution.
 *
 * @param array<string, mixed> $job
 */
function cwi_job_role(array $job): string
{
    $evidence = $job['role_evidence'];
    if ($evidence['publication_signals'] !== []) {
        return 'publication';
    }
    if ($evidence['orchestration_signals'] !== []) {
        return 'orchestration';
    }
    if ($evidence['aggregate_condition_with_needs']) {
        return 'aggregate';
    }
    if ($job['needed_by'] !== [] && ($job['outputs'] !== [] || $evidence['feeds_same_workflow_consumer'])) {
        return 'setup';
    }

    return 'execution';
}

/**
 * @param array<string, mixed>|null $jobPermissions
 * @param array<string, mixed>|null $workflowPermissions
 * @return array<string, mixed>
 */
function cwi_effective_permissions(?array $jobPermissions, ?array $workflowPermissions): array
{
    if ($jobPermissions !== null) {
        return ['scope' => 'job'] + $jobPermissions;
    }
    if ($workflowPermissions !== null) {
        return ['scope' => 'workflow'] + $workflowPermissions;
    }

    return ['scope' => 'repository-default', 'kind' => CWI_KIND_UNRESOLVED, 'shorthand' => null, 'grants' => null];
}

/**
 * Aggregate record for jobs that gate on prerequisites with `always()`.
 *
 * @param array<string, mixed> $job
 * @return array<string, mixed>|null
 */
function cwi_aggregate_record(array $job): ?array
{
    if (!$job['role_evidence']['aggregate_condition_with_needs']) {
        return null;
    }
    $checked = array_map(static fn(array $reference): string => $reference['job'], $job['dependency_result_references']);
    $unchecked = array_values(array_diff($job['needs'], $checked));
    sort($unchecked, SORT_STRING);

    return [
        'gate' => cwi_condition_token($job['if'], CWI_AGGREGATE_CONDITIONS),
        'prerequisites' => $job['needs'],
        'result_checked_prerequisites' => array_values(array_intersect($job['needs'], $checked)),
        'result_unchecked_prerequisites' => $unchecked,
    ];
}

/**
 * @return list<string>
 */
function cwi_local_commands(string $runText): array
{
    $commands = [];
    foreach (CWI_LOCAL_COMMAND_PATTERNS as $pattern) {
        if (preg_match_all($pattern, $runText, $matches) > 0) {
            foreach ($matches[1] as $command) {
                $commands[] = trim((string) preg_replace('/\s+/', ' ', $command));
            }
        }
    }
    $commands = array_values(array_unique($commands));
    sort($commands, SORT_STRING);

    return $commands;
}

/**
 * @param array<string, string> $patterns
 * @return list<string>
 */
function cwi_pattern_hits(array $patterns, string $text): array
{
    $hits = [];
    foreach ($patterns as $signal => $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $hits[] = $signal;
        }
    }
    sort($hits, SORT_STRING);

    return $hits;
}

/**
 * The executable text of a job's `run:` steps: a whole-line shell comment is
 * replaced by an EMPTY line, never removed. Scanning prose reports commands
 * nothing runs (`# ... exact composer constraint "..."` in
 * skeleton-smoke.yml) and a commented-out command is not executed — but
 * deleting the line would splice its neighbours together and let a
 * multi-word pattern invent a command across the seam. Keeping the line as a
 * blank preserves every boundary; the patterns themselves never cross a
 * newline.
 *
 * @param list<mixed> $steps
 */
function cwi_run_text(array $steps): string
{
    $parts = [];
    foreach ($steps as $step) {
        if (!is_array($step) || !is_string($step['run'] ?? null)) {
            continue;
        }
        // Trailing whitespace is dropped so a continuation marker (backtick
        // or backslash) is always the last byte before the newline, which is
        // what the fixed-width continued-line lookbehind above inspects.
        foreach (preg_split('/\R/', $step['run']) ?: [] as $line) {
            $line = rtrim($line);
            $parts[] = str_starts_with(ltrim($line), '#') ? '' : $line;
        }
    }

    return implode("\n", $parts);
}

/**
 * Every string scalar in the job mapping, newline-joined, so signal patterns
 * see raw text rather than JSON-escaped text.
 *
 * @param array<mixed> $job
 */
function cwi_job_text(array $job): string
{
    $parts = [];
    $walk = static function (mixed $value) use (&$walk, &$parts): void {
        if (is_string($value)) {
            $parts[] = $value;
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $walk($item);
            }
        }
    };
    $walk($job);

    return implode("\n", $parts);
}

/**
 * @param list<mixed> $steps
 * @return list<string>
 */
function cwi_action_uses(array $steps): array
{
    $uses = [];
    foreach ($steps as $step) {
        if (is_array($step) && is_string($step['uses'] ?? null)) {
            $uses[] = $step['uses'];
        }
    }
    $uses = array_values(array_unique($uses));
    sort($uses, SORT_STRING);

    return $uses;
}

/**
 * @return array<string, mixed>|null
 */
function cwi_permissions(mixed $permissions): ?array
{
    if ($permissions === null) {
        return null;
    }
    if (is_string($permissions)) {
        return ['kind' => CWI_KIND_LITERAL, 'shorthand' => $permissions, 'grants' => null];
    }
    if (!is_array($permissions)) {
        throw new CiWorkflowInventoryFailure('permissions must be a mapping or shorthand string');
    }
    $grants = [];
    foreach ($permissions as $scope => $level) {
        $grants[(string) $scope] = is_scalar($level) ? (string) $level : null;
    }
    ksort($grants, SORT_STRING);

    return ['kind' => CWI_KIND_LITERAL, 'shorthand' => null, 'grants' => $grants];
}

/**
 * @return array<string, mixed>|null
 */
function cwi_scalar_or_expression(mixed $value): ?array
{
    if ($value === null) {
        return null;
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return [
        'kind' => cwi_has_expression($encoded) ? CWI_KIND_UNRESOLVED : CWI_KIND_LITERAL,
        'value' => $value,
    ];
}

/**
 * @return list<string>
 */
function cwi_sorted_keys(mixed $mapping): array
{
    if (!is_array($mapping)) {
        return [];
    }
    $keys = array_map('strval', array_keys($mapping));
    sort($keys, SORT_STRING);

    return $keys;
}

/**
 * Coerces a scalar, list, or newline-separated block into a list of strings.
 *
 * @return list<string>
 */
function cwi_string_list(mixed $value): array
{
    if ($value === null) {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_map(
            static fn(mixed $item): string => is_scalar($item)
                ? (string) $item
                : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $value,
        ));
    }
    if (is_scalar($value)) {
        $lines = preg_split('/\R/', trim((string) $value)) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    throw new CiWorkflowInventoryFailure('expected a scalar or list');
}

function cwi_has_expression(string $value): bool
{
    return str_contains($value, '${{');
}

/**
 * Canonical JSON rendering: pretty, unescaped, LF, trailing newline.
 *
 * @param array<string, mixed> $inventory
 */
function cwi_render(array $inventory): string
{
    return json_encode(
        $inventory,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
}

/**
 * Byte offset of the first difference between two renderings, for the
 * --check drift summary. Returns the shorter length when one is a prefix of
 * the other, and -1 when they are identical.
 */
function cwi_first_difference(string $left, string $right): int
{
    $limit = min(strlen($left), strlen($right));
    for ($index = 0; $index < $limit; ++$index) {
        if ($left[$index] !== $right[$index]) {
            return $index;
        }
    }

    return strlen($left) === strlen($right) ? -1 : $limit;
}
