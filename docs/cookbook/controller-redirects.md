# Cookbook: Controller Redirects

**Audience:** application authors coming from Drupal, Laravel, or Symfony.
**Substrate:** `waaseyaa/routing`.
**Contract:** [`app-controller-invocation.md`](../specs/app-controller-invocation.md).

Waaseyaa supports both composition-first plain controllers and an optional
thin controller base. Both use the same request-scoped `Redirector` and the
same named route table that matched the incoming request.

## Recommended: inject `Redirector`

A plain final controller can request the redirector directly on an action:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Waaseyaa\Routing\RedirectResponse;
use Waaseyaa\Routing\Redirector;

final class TodoController
{
    public function store(Redirector $redirector): RedirectResponse
    {
        // Save the todo, then use 303 so the browser follows with GET.
        return $redirector->toRoute('todo.index', status: 303);
    }
}
```

Constructor injection is also supported when several actions share it:

```php
final class TodoController
{
    public function __construct(
        private readonly Redirector $redirector,
    ) {}

    public function destroy(): RedirectResponse
    {
        return $this->redirector->toRoute('todo.index', status: 303);
    }
}
```

## Optional: extend the thin controller base

Developers who prefer Drupal-style protected helpers may extend
`Waaseyaa\Routing\Controller`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Waaseyaa\Routing\Controller;
use Waaseyaa\Routing\RedirectResponse;

final class TodoController extends Controller
{
    public function store(): RedirectResponse
    {
        return $this->redirectToRoute(
            'todo.show',
            ['todo' => 42],
            status: 303,
        );
    }
}
```

If the subclass declares its own constructor, inject `Redirector` and pass it
to `parent::__construct($redirector)`. Other dependencies stay explicit. The
base controller does not expose the container, entity manager, current
account, configuration, translation, or rendering services.

## Direct paths are local only

`Redirector::to()` and `$this->redirect()` accept a local absolute path such
as `/todos` or `/todos?status=open`. Empty paths, relative paths without a
leading slash, external URLs, protocol-relative URLs, backslash authority
tricks, and ASCII control characters throw `InvalidArgumentException`.

```php
return $redirector->to('/todos', status: 303);
```

Prefer `toRoute()` or `redirectToRoute()` for application destinations. Route
parameters are encoded by the router, and missing routes or required
parameters fail loudly during development.

External redirects are intentionally not a convenience helper. Integrations
that redirect off-site must define and enforce their own trusted-destination
allowlist.
