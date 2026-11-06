# FW-ARTIFACT-MANIFEST-LAYOUT-01

Based on reviewed local producer 495b1ff. Owns Foundation and CLI only. No
access/audit source changes, publication or hosted activation. The approved
application design is retained at Sheguiandah Sprint packet
compiled-cache-root-proposal.md, SHA
fbf377164530f353881eaef212b3a92b9846764538e25eb2a80ffee0fce8ade5.

The existing shared runtime cache allows two different release manifests to
overwrite one another during code switching. A single canonical compiler-owned
layout resolves an immutable Composer opt-in and exact host-created binding.
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

Acceptance covers strict canonical opt-in/binding and symlink refusal, missing
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
