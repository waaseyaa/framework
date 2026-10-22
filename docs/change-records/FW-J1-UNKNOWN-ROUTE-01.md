# FW-J1-UNKNOWN-ROUTE-01: absent-route failure diagnosis and bounded repair

Journey: FW-AUDIT-J1 / #3199. Exact reviewed base:
`4122814ad68fd7c8aa17f0d68b807c90367ab11f`, including installer repair
`1a5bdf11fbe01f013fa2052e2662cd870b82529c`. Isolated branch:
`codex/j1-unknown-route-3199`. Native Git is the Windows Git executable.

## Bounded plan and ownership, before implementation

Reproduce the installed Apache/PHP-FPM unknown-route 500 on the exact base,
then establish the actual exception origin and response mapping before choosing
a repair. Generic public output and a wholly redacted private critical log do
not identify the cause. #3203 / FW-FAILURE-HANDLING-01 owns cross-boundary
failure assessment; #3198 separately owns loss of safe diagnostic metadata.
Neither issue is presumed to explain the other. Preserve existing issue scope.

Only a diagnosed defect and a failing regression authorize the smallest owning
repair. An absent application route must produce 404. Unexpected errors retain
a sanitized 500, while safe private diagnostic context must remain useful
without exposing secret values, request payloads or raw sensitive traces.
Evaluate the maintained Symfony exception/HTTP mechanisms against the actual
boundary; do not introduce a parallel renderer or generic diagnostics system.
Record any separate diagnostic change and its evidence explicitly.

Preserve all prior evidence, applications, reviewed candidates and stopped
containers. A fresh exact-candidate installation uses its own mirrored vendor,
the frozen Linux Apache/PHP-FPM image and canonical production initialization
and field-access activation. Do not alter production environment, secret,
database or field-access safeguards to diagnose or repair the failure.
Private diagnostic probes must be names/types-only and isolated from the
preserved failing applications; no production debug mode or disabled redaction.

Add discriminating coverage for the demonstrated cause and affected status,
refusal and disclosure boundaries. Run focused affected tests, governed checks
for changed paths and normal hooks. Independently review the immutable final
candidate and evidence; address findings and requalify affected changes.

Qualify both dev and no-dev installed profiles, each generating its own
canonical activation artifact from its actual inputs. Preserve their distinct
fingerprints. Recheck cold/warm/restarted app-owned Twig homepages, absent-route
status, unexpected sanitized 500, stranger access, missing required secret and
unavailable SQLite. Stop at the next newly demonstrated boundary rather than
expanding the repair. WSL2 candidate evidence is separate from published-package
proof and S1 consumer certification. No push, merge, release, deployment or
#3199 closure is authorized.

## Diagnosed cause and contract decision

The untouched exact base returned 500 at the same absent path in a fresh
mirrored installation. A second independently installed and canonically activated
application added an application-owned diagnostic route before initialization.
That route replayed the actual matched generated closure with the dispatcher's
named parameters, recording names/types only. It confirmed route
site.page.detail, route parameter slug, callable parameter request, throwable
Error, and an exact known-message predicate for Unknown named parameter $slug.
No exception message, argument, raw trace or environment value was exported.
Both diagnostic applications and containers are stopped and preserved.

The owning recipe must declare its terminal slug parameter in the generated
closure. Preserve strict dispatch binding, alias resolution, entity/field
authorization and the controller's existing Not found/404 branch. Do not
reinterpret arbitrary errors as 404 or silently discard unmatched parameters.
The recipe semantic digest must change with this generated contract.

The diagnostic defect is separate (#3198): ControllerDispatcher concatenates a
raw exception trace into one message. An authorization-related frame triggers
the mandatory denylist and erases the entire message. Submit a fixed event plus
the existing structured Throwable context to LogManager instead. Its final sink
sanitizer already retains type/code and sanitized messages/previous exceptions,
omitting trace arguments and request payloads. Do not weaken that sanitizer or
add an exception formatter. Other kernel diagnostic boundaries remain #3198's
unresolved scope; this slice repairs the demonstrated dispatch boundary only.

Maintained infrastructure evaluation: the installed lock pins Symfony Routing
and HttpFoundation 7.4.13. Symfony HttpKernel 7.4 ArgumentResolver and its
ArgumentResolverTest provide maintained signature-based controller argument
resolution; HttpKernel's exception event provides response integration.
Neither http-kernel nor error-handler is installed. Their adoption would alter
the shared dispatch/event lifecycle and dependency graph. This repair does not
add a generic resolver, renderer or exception mechanism: correct the generated
consumer of the current named dispatch contract. Symfony ErrorHandler 7.4
FlattenException preserves messages, traces and trace arguments; flattening
does not replace Waaseyaa's sensitive-value custody and final sink sanitization.
Use the existing structured Throwable path for this diagnostic fix. #3203
retains the broader component migration assessment and its review trigger.

Primary comparison sources:
- https://symfony.com/doc/7.4/components/http_kernel.html
- https://github.com/symfony/http-kernel/blob/7.4/Controller/ArgumentResolver.php
- https://github.com/symfony/http-kernel/blob/7.4/Tests/Controller/ArgumentResolverTest.php
- https://github.com/symfony/error-handler/blob/7.4/Exception/FlattenException.php

Focused plan: real generated root/prefixed slug dispatch through HttpKernel;
callable and domain-router 500 diagnostics through the formatted mandatory sink;
existing generated recipe, homepage, Inertia and HTTP refusal tests. Capture red
before repair, green afterwards. Run governed preflight and independent immutable
review, then exact installed dev/no-dev HTTP qualification. Broaden only for a
new demonstrated failure, shared input change or reviewer finding.

## Development verification and evidence custody

Evidence bundle: 3199-evidence/20261022-unknown-route-3199 outside the checkout.
qualify-base.py and diagnose-cause.py preserve the executed baseline/probe
procedures; their step receipts retain complete argv, output, exit and runtime.
The baseline and cause applications use independent vendors and canonical
install:init plus read-only preflight and explicit artifact publication.

Before implementation, Linux red-tests.log recorded six failures: root and
prefixed generated detail requests returned 500, and four callable/router
formatted-sink cases lost the fixed event. Native diagnostic-red.log separately
confirmed the four diagnostic failures. Green-final-tests.log subsequently
passed 79 tests / 343 assertions, zero skips, PHP 8.5.12, PHPUnit 13.1.14,
10.877 seconds. It includes both actual HttpKernel generated-route regressions,
all skeleton homepage tests, strict named-parameter refusal, existing recipe
tests, callable/router public 500/privacy tests, LogManager and RedactorProcessor.

An initially included unchanged HttpKernelBootFailureTest assumes a directory
cannot be created under /nonexistent; this Docker test process runs as root.
It instead reached DEFAULT_TYPE_MISSING. The exact base reproduces the same
failure in base-boot-tests.log. It is retained as a runner-condition diagnostic,
not repaired or claimed as a passing candidate check. The affected boundary
qualification uses actual production CLI/HTTP missing-database refusals;
unchanged installer and safeguard evidence remains reusable by source/input
identity. Green-final excludes that unrelated root-dependent test.

The final installed procedure adds only explicitly synthetic application
auth/failure routes before initialization; framework packages and the canonical
front controller remain exact. Each profile checks its own environment/artifact
restoration hashes, absent database noncreation, stranger refusal, generated and
unmatched 404, sanitized unexpected 500 and private structured diagnostics.
Each profile produces its own ready activation artifact from its actual inputs.
