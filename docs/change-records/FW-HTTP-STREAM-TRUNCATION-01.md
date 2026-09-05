# FW-HTTP-STREAM-TRUNCATION-01 — fail-closed StreamHttpClient body reads

Status: candidate  
Anchor mirror: waaseyaa/framework#2708  
Parent candidate: `origin/main`

## Intent

Stop `StreamHttpClient` from returning a truncated response body as HTTP
success. The memory ceiling remains; a prefix of an over-limit body or a body shorter than its declared length is no
longer a successful `HttpResponse`.

## Defect

`fetch()` called `stream_get_contents($handle, $maxResponseBytes)` and treated
any non-`false` string as a complete body. Hitting the cap produced
`status=200` with fewer bytes than `Content-Length`.

## Decisions

1. Over-limit, Content-Length mismatch, EOF before the declared length, and mid-body timeout
   throw typed `HttpRequestException` with `$response === null`.
2. A complete body whose size equals `maxResponseBytes` still succeeds,
   including chunked and connection-close bodies with no `Content-Length`.
3. Declared `Content-Length` above the ceiling is rejected before the body is
   read so memory stays bounded.
4. Newly added diagnostic messages name only the failure class (`exceeded` /
   `incomplete`). They do not embed response bytes, `Authorization`, or the
   URL. Existing connect/read `transportFailure()` messages are unchanged.

## Verification

`packages/http-client/tests/Unit/StreamHttpClientTransportTest.php` covers
below / exact / above limit, chunked transfer, absent `Content-Length`,
mismatched `Content-Length`, mid-body timeout, over-limit 5xx, and complete
404. Existing TLS, redirect, CRLF, and scheme tests remain.

## Response framing boundary

HEAD, 1xx, 204, and 304 responses have an empty body regardless of metadata
Content-Length. Other length-delimited bodies stop at that length without
waiting for the peer to close the connection. EOF before the declared length
and timeouts while reading that body fail closed.

PHP's HTTP wrapper dechunks responses and removes Transfer-Encoding before
exposing the stream. The byte ceiling still applies to decoded chunked bodies,
but this transport cannot independently detect a missing chunk terminator. For
connection-close bodies, EOF defines completion; no independent expected length
is available. This change does not claim to validate those hidden framing cases.
