# FW-MEDIA-DELIVERY-SERVICE-01

Base: 42c94f5aff833e696aa1d56b6e300673df7c76a4. Local implementation only; no publication authorization.

A consuming published-Job attachment controller needs canonical managed media delivery after its own parent/reference/bundle authorization. The existing public source reader cannot deliver bytes and the internal router has no supported injectable response boundary. A redirect loses the narrow request principal.

Plan: expose MediaDownloadResponderInterface::download(Request, string mediaId): Response on the existing router; bind it lazily once and reuse that same instance in the canonical route chain. The producer method duplicates request context and chooses the canonical download discriminator internally. Existing matching-account/principal, entity-view, audited source lookup, URI and path confinement, MIME, Range and concealed404 behavior remain unchanged. No new grant, URI parser or generic filesystem/HTTP engine.

Symfony fit: reuse locked Symfony HttpFoundation v7.4.22 Request::duplicate and existing Response implementation. This change adds only Waaseyaa domain authorization/service acquisition, not a generic mechanism or dependency. No Composer constraint/lock change.

Verification: focused public-service regression must fail before implementation; owning media router/service-provider suite; completed real kernel resolution and canonical-chain instance identity; config root precedence; request/principal preservation and refusal/404 behavior. Independent reviewer browser_transport; integration owner root. Broaden only on unexplained shared-kernel failure or mandated hooks. Candidate-local offline Composer install completed from existing lock with network disabled.

Local gate correction: the first preflight properly refused the new interface Request import without a narrowly justified allowlist entry and requested the owning Media README update. Both authorities are updated; no scanner or import policy was weakened.
