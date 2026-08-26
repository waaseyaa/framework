# Protected inline media delivery

Stable change record: `protected-inline-media-20260825`

## Intent

Add an explicit Framework delivery surface for embedding an already-authorized
PDF in a same-origin browser frame. The media entity remains the sole authority
for both metadata and bytes; applications must not expose the backing storage
path or create a second authorization path.

## Contract decision

- Keep `GET /media/{id}/download` compatible, including its existing top-level
  document-navigation behavior.
- Add `GET /media/{id}/view` as a distinct route handled by the same stateless
  router and the same entity-view decision, audited source reader, and confined
  path resolver.
- The view route returns `inline` only for a code-owned allowlist containing
  `application/pdf`. The MIME type is detected from the bytes; filename,
  persisted MIME metadata, and request headers cannot opt content in.
- View responses remain under the kernel's canonical `X-Frame-Options:
  SAMEORIGIN` and `nosniff` response policy without replacing a deployment's
  configured CSP. Both download and view retain complete-body delivery,
  `Accept-Ranges: none`, and sanitized filenames.
- Every failed identity, authorization, source, confinement, or byte lookup
  returns the existing byte-identical 404 before protected metadata is emitted.

## Verification plan

1. Characterize the existing download response and concealment envelope.
2. Add red integration tests for iframe-shaped PDF delivery, MIME spoofing,
   framing policy, byte-identical denial, path confinement, range behavior, and
   sequential request reuse.
3. Add the route and smallest router change needed to satisfy those tests.
4. Update the enduring media/infrastructure contracts and changelog fragment.
5. Run focused tests, all three suites, random order, full preflight, packaged
   consumer boots, and HTTP/FrankenPHP acceptance before publication.

## Explicit exclusions

No public aliases, signed bypass URLs, application-specific document types,
storage migration, partial-content implementation, or alternate authorization
service is introduced by this change.
