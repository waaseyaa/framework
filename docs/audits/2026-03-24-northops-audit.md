## NorthOps Framework Compliance Audit — 2026-03-24

**Baseline audit.** First audit of northops-waaseyaa against Waaseyaa framework conventions.

### Scope

The codebase has **3 implemented PHP files**. All other directories (`Access/`, `Domain/`, `Controller/Api/`, `Command/`) contain only `.gitkeep` placeholders. CLAUDE.md describes a rich pipeline architecture that is planned but not yet built.

| File | Purpose |
|------|---------|
| `src/Entity/ContactSubmission.php` | Contact form entity |
| `src/Controller/MarketingController.php` | Public marketing pages + contact form |
| `src/Provider/AppServiceProvider.php` | Routes, controller wiring |

---

### Compliance Checklist

| Category | Check | Result | Notes |
|----------|-------|--------|-------|
| Entity | Extends `ContentEntityBase` | **Pass** | `ContactSubmission.php:9` |
| Entity | Constructor `(array $values = [])` | **Pass** | `ContactSubmission.php:22` |
| Entity | Uses `$this->entityTypeId` in parent call | **Pass** | `ContactSubmission.php:28` |
| Entity | `final class` + `declare(strict_types=1)` | **Pass** | |
| Entity | No ActiveRecord patterns | **Pass** | |
| Entity | No raw PDO | **Pass** | |
| Entity | No Laravel-isms | **Pass** | |
| Registration | Uses named constructor params | **Pass** | `config/entity-types.php:24-28` |
| Registration | Includes `fieldDefinitions` | **Gap** | Not present — acceptable for now |
| Provider | `register()` vs `boot()` separation | **Fail** | `register()` empty, no bindings published |
| Provider | Per-domain scope | **Pass** | Single provider appropriate for current size |
| Access | Policies exist | **N/A** | Not yet built |
| Controller | No superglobal access | **Pass** | Uses `Request` object throughout |
| Controller | Returns `Response` objects | **Fail** | Returns `string`, wrapping in provider |
| Routes | Uses access options | **Pass** | All routes use `->allowAll()` |
| Routes | No hard-coded paths | **Fail** | Hard-coded `/contact` redirect |

**Result: 10 pass / 3 fail / 1 gap / 1 N/A**

---

### Framework Workarounds Found

#### 1. Service locator for Twig — MEDIUM

**File:** `src/Controller/MarketingController.php:22-30`

```php
private function twig(): Environment
{
    return SsrServiceProvider::getTwigEnvironment();
}
```

Static call to `SsrServiceProvider::getTwigEnvironment()` bypasses DI. `Twig\Environment` should be constructor-injected.

**Framework provides:** Constructor injection via ServiceProvider `singleton()` / `resolve()`.

#### 2. Manual controller instantiation — MEDIUM

**File:** `src/Provider/AppServiceProvider.php:17-31`

```php
private ?MarketingController $controller = null;

private function controller(): MarketingController
{
    if ($this->controller === null) {
        $this->controller = new MarketingController(...);
    }
    return $this->controller;
}
```

Reimplements `singleton()` manually. Should register in `register()` and use `$this->resolve(MarketingController::class)`.

**Framework provides:** `ServiceProvider::singleton()`, `ServiceProvider::resolve()`.

#### 3. Discord webhook via raw `file_get_contents` — MEDIUM

**File:** `src/Controller/MarketingController.php:109-138`

```php
@file_get_contents($this->discordWebhookUrl, false, $context);
```

Three issues stacked:
- Infrastructure concern (HTTP call) lives in a controller, not an event subscriber
- Uses `file_get_contents` with manual stream context instead of a proper HTTP call
- `@` error suppression silently swallows failures

**Framework provides:** `EventBus` + `DomainEvent` for decoupling side effects from controllers. No shared HTTP client exists yet, but the event subscriber pattern is the correct structural fix.

#### 4. Controller returns strings, not Response objects — LOW

**File:** `src/Controller/MarketingController.php:33-54`

Controller methods return raw `string` (rendered Twig HTML). The `SsrResponse` wrapping happens in route closures inside `AppServiceProvider.php:38-96`. The controller should own its response type.

#### 5. Hard-coded redirect path — LOW

**File:** `src/Controller/MarketingController.php:106`

```php
return new RedirectResponse('/contact?status=success');
```

Should use `WaaseyaaRouter::generate('marketing.contact', [...])` for route-name safety.

#### 6. Inline validation in controller — LOW

**File:** `src/Controller/MarketingController.php:67-93`

Field validation (required checks, email format, length limits) is done inline in `submitContact()`. The framework ships a `validation` package. More importantly, this logic belongs in the domain layer (e.g., `LeadFactory`) per the planned architecture.

---

### What's Clean

- **Entity pattern is fully compliant** — `ContactSubmission` follows all framework conventions
- **No forbidden dependencies** — zero Laravel-isms, no raw PDO, no `$_ENV`
- **No superglobal access** — `Request` object used throughout
- **Route definitions are idiomatic** — `WaaseyaaRouter::addRoute()` with `RouteBuilder`
- **Route naming is consistent** — `marketing.*` namespace

---

### Recommendations (prioritized)

| Priority | Action | Effort |
|----------|--------|--------|
| 1 | Inject `Twig\Environment` via constructor, register controller as singleton | Small |
| 2 | Extract Discord notification to an event subscriber using `EventBus` | Medium |
| 3 | Have controller methods return `SsrResponse` directly | Small |
| 4 | Replace hard-coded `/contact` with router `generate()` | Trivial |
| 5 | Move validation to domain service when building pipeline | Deferred |

Items 1-4 should be addressed before Phase 1 pipeline work begins, to establish correct patterns for the larger build.

---

### Trend

First audit — no previous baseline for comparison.
