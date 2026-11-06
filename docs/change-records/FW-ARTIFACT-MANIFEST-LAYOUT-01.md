# FW-ARTIFACT-MANIFEST-LAYOUT-01

Based on reviewed local producer 495b1ff. Owns Foundation and CLI only. No
access/audit source changes, publication or hosted activation. The approved
application design is retained at Sheguiandah Sprint packet
compiled-cache-root-proposal.md, SHA
fbf377164530f353881eaef212b3a92b9846764538e25eb2a80ffee0fce8ade5.

The existing shared runtime cache allows two different release manifests to
overwrite one another during code switching. A single canonical compiler-owned
layout resolves an immutable fixed code marker and exact host-created binding.
It keeps all ordinary runtime/configuration custody unchanged and isolates
packages.php per artifact digest. Missing required custody fails before kernel
or db:init creates database state. Direct private-copy compiler callers cannot
silently select live runtime cache; only an explicitly isolated typed context
can compile in a separately prepared private root.

Symfony fit: existing HttpFoundation/Filesystem runtime components remain
unchanged. This is artifact custody and manifest authority domain policy, not a
new generic filesystem, URI or deployment abstraction. The established compiler
atomic-rename flow remains; selected-mode exclusive temporary creation is
required because generic Filesystem::dumpFile follows target links and cannot
satisfy the fixed no-follow temporary custody contract. Legacy behavior remains
unchanged. No new dependency or operator engine is introduced.

Acceptance covers strict canonical marker/binding and symlink refusal, missing
preparation, scope isolation, concurrent different logical metadata, protected
legacy cache preservation, known-missing stamps, temporary symlink refusal,
actual HTTP/console boot path parity, scoped CLI clear, and unchanged existing
compiler/kernel/CLI tests. Host artifact integrity, reserved-overlay absence in
the archive, exclusive ownership/custody and exact binding-vs-request digest
are separate operator admission checks. Producer witness is observation only,
not artifact integrity proof, approval or a production recovery claim.

No cache directory or binding is allocated by the compiler. The operator must
prepare and attest them, use a reviewed versioned overlay/layout contract,
validate actual FPM witness and retained rollback state, then perform any
separately authorized activation through the maintained transaction lifecycle.

## Maintained filesystem fit and bounded exception

Owner: Foundation package-manifest compiler maintainer. Re-review this exception
when Symfony offers a no-follow, no-parent-allocation publication primitive or
when the manifest binding/custody contract changes.

Checked the installed Symfony Filesystem v8.0.9 source
[d1ec4543](https://github.com/symfony/filesystem/blob/d1ec4543d5c6c2dac78503c2fae5ea0b3608ce40/Filesystem.php),
the supported 8.1 branch at
[58f57b6a](https://github.com/symfony/filesystem/blob/58f57b6a33e3495f7f41e01258f1ae5ef76cccd2/Filesystem.php),
its [dump-file tests](https://github.com/symfony/filesystem/blob/58f57b6a33e3495f7f41e01258f1ae5ef76cccd2/Tests/FilesystemTest.php),
and the [official component contract](https://symfony.com/doc/current/components/filesystem.html#dumpfile).
`dumpFile` is an appropriate general atomic writer. Its intentional symlink
following and missing-parent allocation differ from this preallocated,
no-follow manifest authority. The existing compiler therefore retains only its
small exclusive temporary-file/rename step and domain custody checks, without
a generic filesystem facade or new dependency. No Symfony version change is
included. A disposable probe against installed v8.0.9 reproduced both behaviors;
Foundation layout regressions instead require missing-parent and temporary-link
refusal with unchanged protected state. Cached Apache/FPM and explicit CLI
controls separately prove success, refusal, isolation, selected clearing and
legacy-cache preservation. Engagement evidence: Sprint1 manifest-layout-producer
symfony-fit.php and symfony-fit-result.json; source and test pins above remain
portable if that engagement evidence is unavailable.

## Pre-publication selector correction

The original unpublished Composer-extra selector changed Composer content-hash
inputs when a release profile injected it after lock generation. The final
contract replaces it entirely with `.waaseyaa/manifest-cache-layout`, exact
18-byte `artifact-bound-v1` plus LF, generated only by an explicit governed
artifact profile and included in its archive integrity map. Both reserved
marker/binding paths must be absent from committed application source before
assembly; the host verifies that external authority, not this producer.
Composer files and main-lane records remain byte-identical. No metadata lock
rewrite, main-record reinterpretation or second selector is introduced.

The frozen original source/evidence remains historical. This implementation
starts from 8ff62072d63beb5c7ff446e32cde75a129238ab0 and preserves the reviewed
Admin source/dist and filesystem-fit documentation. Only selector, owning tests
and this contract documentation change.

Independent review found a real requiredness defect: an unreadable controls
directory could appear absent and select legacy. The fixed-name lookup now
checks current-principal search/read access before absence fallback. The
regression was reproduced red under non-root UID1000, then proved green.
Negative tests explicitly reject PHPUnit assertion exceptions so a test's own
fail cannot be swallowed by its RuntimeException refusal catch.

Selected layout declares a POSIX filesystem capability and refuses unsupported
Windows selection before writes. A separate symlink-free legacy/default test
runs on native Windows. Existing native-host command selections remain intact.
