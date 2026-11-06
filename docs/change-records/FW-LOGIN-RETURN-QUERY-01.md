# FW-LOGIN-RETURN-QUERY-01

Preserve the local requested query when authorization redirects an anonymous
render request to sign-in, and when the default forbidden page offers sign-in.
The current getPathInfo call drops content type, status and search filters.
Use Symfony HttpFoundation Request::getRequestUri for the original local path
and query, keeping the existing RedirectValidator and URL/HTML escaping.
No authentication, account authority, route admission or API response changes.

The producer is based on 644702dd9a58c87d7fadfef82d0e203e6d2dbc6d.
The middleware source initially matched Sheguiandah's installed access split
reference efe2e62d0ed4003ac769da6d7e1be2965e4b16df byte for byte.
Two focused regression cases failed before the change because the query was
absent. The full AuthorizationMiddleware suite passes after the change:
17 tests, 39 assertions, PHP 8.5.8 and PHPUnit 11.5.56. The temporary test
bootstrap loads producer middleware and testing factory explicitly ahead of
integration autoloaded classes. This is local candidate evidence only.

Symfony fit: installed HttpFoundation 7 Request::getRequestUri is the maintained
source of path plus query, already used as the HTTP request boundary. No
additional dependency, URI builder or redirect validator is introduced.
The existing validator still rejects protocol-relative and backslash targets
and control bytes; existing escaping still protects the sign-in link.

Consumer adoption remains separate: the app main-lane contract expects exact
Framework split source commits. This local producer commit must not be labeled
as a published split reference. No remote push, merge, split or publication is
authorized by this candidate. A separately approved split publication, or an
explicitly reviewed portable local-package adoption contract and its lock/gates,
is required before application staging qualification.
