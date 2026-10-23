# FW-J1-PUBLISHED-S1-01: alpha.308 publication and J1 consumer qualification

Owner: Framework release/J1 maintainer. Mirrors #3199; #3198 and #3203 retain
their wider diagnostic and assessment ownership. This continues
FW-J1-INTEGRATION-01 and preserves all prior candidates, applications and evidence.

## Authorization and immutable starting point

On 2026-10-23 Russell explicitly authorized: "ok, I approve the alpha.308 release
cut, including package publication and splitting. Send that to Find the next
roadmap task and have it continue with published/S1 qualification."
Orchestrate relayed that exact instruction from its user-visible task.
This supersedes the earlier proposal's historical NOT-authorized status for
this exact action. No production deployment or support expansion is authorized.

Approved integrated base: 1545bad0790829e7673c155007833dbbbe3e45a3.
Its full qualification 39878615826, main feedback 39879660691 and read-only
Readiness 39879677924 are green; those results do not qualify a later release
commit. All prior application/container custody remains unchanged.

## Plan recorded before execution

1. Recheck live main, active rules, alpha.308 tag availability and every
   non-completed workflow state. Resume an existing/uncertain equivalent cut;
   never duplicate it or advance main during release split/fan-out.
2. Execute the sole canonical action:
   `gh workflow run release-cut.yml --repo waaseyaa/framework --ref main -f version=v0.1.0-alpha.308`.
   Preserve all five gates on the generated version-swept release SHA, then
   track cut, split, actual Packagist visibility, release assets and Skeleton
   synchronization to definite results. Capture the first failure before repair.
3. Verify release asset SHA-256, main/tag ancestry, actual source/dist/split refs,
   complete installed first-party cohort and template/consumer lock identities.
   No local path repositories, vendor overlays or fabricated solver versions.
4. Qualify separate fresh published dev/no-dev applications on an observed
   task-owned Ubuntu24.04 x86_64/local ext4/Apache2.4/PHP-FPM8.5 lane. Record
   current PHP8.5 patch, Composer2.10, SQLite>=3.40,<4 and single-node/file topology.
   WSL2 and the previously inspected Ubuntu26.04/Debian13 hosts remain outside
   that consumer evidence. Reuse suitable governed hosted capability; any new
   execution harness needs a bounded contract and independent review.
5. Follow the canonical minimal site/init/verify sequence, each installation's
   read-only names-only preflight and restricted --write-artifact operation.
   Preserve refusal on missing/stale/malformed/blocker artifacts. Never hand-
   write an artifact or make install:init its producer.
6. Prove repeat/content preservation through supported entity commands/API,
   own Twig cold/warm/restarted home, generated/unmatched404, safe controlled500
   and private diagnostics, stranger/secret/database/platform refusals and
   restoration. Preserve the next failure without unrelated scope expansion.
7. Independently review new execution/safeguard changes and final claims.
   Reconcile J1-A through J1-F explicitly; published qualification and the
   observed S1 tuple do not automatically fill the separate S0-SHEG-02 global
   certification record. Keep #3199 open unless its actual acceptance is proven.

Exact argv, exits, generated release/input identities, raw command/HTTP outputs,
review and preservation receipts are retained under the portable evidence packet
identity `20261023-alpha308-s1-3199`. Source is isolated on the task-owned
`codex/j1-published-s1-3199` branch; original checkouts are not advanced.

The single canonical cut 39971597002 completed at release commit
baead2150a78883b82536282d1181cf5b7a92a10. Exact-release full CI 39971708958 and
Readiness 39973030447 passed. Split/publication 39975413783 completed, including
every-package installability and GitHub Release publication. Skeleton sync
39975413572 and its default smoke 39975460551 passed. The downloaded provenance
and SBOM hashes match SHA256SUMS. These establish publication inputs; the new
published Apache/FPM consumer qualification remains a separate required run.

## Qualification harness decisions before execution

Extend the existing Skeleton Smoke workflow with an explicit manual-only boolean
selection and separate Ubuntu24.04 job. Its default/automatic smoke remains
unchanged. Run the reviewed branch ref without changing main during publication.
Published consumer packages remain independent of the harness checkout/vendor.

The initial unexecuted Python orchestration draft is replaced: the existing
locked Symfony Process v8.0.8, Filesystem v8.0.9 and HttpClient v7.4.20 directly
fit argv execution/deadlines, isolated file operations and bounded HTTP requests.
Their installed sources and official versioned component docs/test sources were
inspected. Install the harness checkout's own locked tooling vendor; never share
it with consumers. No dependency or generic runner framework is added. Waaseyaa
policy remains exact published identity checks, lifecycle/refusal assertions,
first-failure capture and scope/custody decisions. The docs are
https://symfony.com/doc/8.0/components/process.html,
https://symfony.com/doc/8.0/components/filesystem.html and
https://symfony.com/doc/7.4/http_client.html. Component line upgrades remain a
separate repository dependency decision, not a reason to fork a mechanism here.

The generated verification script explicitly requires vendor/bin/phpunit. J1's
production-composition contract permits development verification before the
no-dev boundary. Run composer site-verify in the dev installation; the separate
fresh no-dev installation must lack that verifier and instead run the canonical
site:doctor --strict --format=json, its own field scan/artifact, ordinary boot
and the complete serving/refusal sequence. Do not install or borrow dev tools
to satisfy a harness-only requirement in the production installation.

The first governed preflight on harness 52cf446b failed exactly two inventory
gates, with 43 other executed gates passing. The schema scanner recognized two
Filesystem artifact rename calls as legacy schema methods; these are isolated
test-tooling, not database DDL. The SQLite scanner recognized the negative
Composer diagnostic's literal extension name; this is tooling-query, not a
connection constructor. Add only those verifier-owned exact-path classifications
and canonically regenerate their complete rosters. Existing runtime authority,
scanner patterns and refusal rules remain unchanged. Retain the failed receipt
and independently review these safeguard inventory changes before execution.
The scanner entrypoints pass natively. Existing architecture tests that require
POSIX executable bits or invoke packaged-form Bash entrypoints cannot pass on
native Windows; retain that diagnostic result and run their exact files on the
new hosted Linux lane before consumer qualification. Do not weaken those tests.

The native hosted VM is ephemeral. Before teardown, stop each task container and
retain its inspect metadata plus complete exported root filesystem. Docker export
excludes mounts: separately archive the complete ext4 bind tree, including app
vendor, normal .env/.env.local, SQLite database/WAL/SHM, configuration and every
unredacted raw stream. Encrypt the whole snapshot with maintained age to Russell's
known SSH public-key fingerprint SHA256:AZ4w6C+xBaNvGPBvgcd1J7U/hFMhAYMN24MrFpb4HXI.
Prove local encryption/decryption with the existing private identity before the
hosted run; only the public key is supplied to CI. Retain plaintext/ciphertext
hashes, recipient identity and a downloaded ciphertext/hash receipt. No owner
private key enters CI. Restoring requires decrypting locally, verifying the tar
hash, restoring numeric ownership/modes/ACLs/xattrs, importing the exported
container rootfs, and rebinding its archived app tree according to inspect data.
This preserves restorable bytes, not the lifetime of an ephemeral VM/container ID.

The existing private identity successfully decrypted an age v1.3.2 synthetic
round-trip locally; the verified release binary/archive digest and recipient
fingerprint are retained in `recipient-verification.json`. Neither the private
identity nor plaintext snapshots enter CI uploads. The hosted lane has a 60-minute
job limit: 30 minutes of qualification work, up to 15 minutes for quiesce/export/
sealing and the remaining upload margin. Every child command and HTTP operation
also has a bounded timeout. Published S1 results require both workflow success
and local verified download/decryption, not just a profile PASS file.

Public artifact uploads are restricted to sanitized command/HTTP records,
names-only scans/artifacts, package/lock/runtime identities and encrypted snapshots.
Canonical app secrets and the private synthetic diagnostic payload are redacted
from public streams; original bytes remain only in the encrypted snapshot. Never
upload the app tree, plaintext archive or container export directly. Capture an
unexpected failure before retry/restoration and stop that profile. The other
profile has separate state and may continue only with its required inputs present.
Per-command and whole-qualification deadlines reserve quiesce/encryption/upload
time. Timeout, export, sealing, upload or local-download/hash failure leaves
custody incomplete and cannot yield complete qualification. New failed instances
receive immutable snapshot identities; no prior instance is reused or repaired.
