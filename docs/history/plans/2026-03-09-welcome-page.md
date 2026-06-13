# Welcome Page Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship a branded welcome homepage for new Waaseyaa apps — a self-contained page with framework branding and getting-started guidance, resolved via a new `home.html.twig` template for the `/` path.

**Architecture:** Add `home.html.twig` to the template candidate list in `RenderController::renderPath()` when the path is `/`. The template is a single self-contained HTML file with inline CSS and one Google Font import. Ships in both `skeleton/templates/` (app-level) and `packages/ssr/templates/` (framework fallback).

**Tech Stack:** PHP 8.3, Twig, HTML/CSS, Google Fonts (Inter)

---

### Task 1: Add `home.html.twig` to template resolution for `/`

**Files:**
- Modify: `packages/ssr/src/RenderController.php:18-56`
- Test: `packages/ssr/tests/Unit/RenderControllerTest.php`

**Step 1: Write the failing test**

Add two tests to `RenderControllerTest.php` — one verifying `home.html.twig` is tried first for `/`, one verifying it's NOT tried for other paths:

```php
#[Test]
public function renderPathTriesHomeTemplateForRootPath(): void
{
    $twig = new Environment(new ArrayLoader([
        'home.html.twig' => '<main>Welcome Home</main>',
        'page.html.twig' => '<main>Generic Page</main>',
    ]));
    $controller = new RenderController($twig);

    $response = $controller->renderPath('/');

    $this->assertSame(200, $response->statusCode);
    $this->assertSame('<main>Welcome Home</main>', $response->content);
}

#[Test]
public function renderPathDoesNotTryHomeTemplateForNonRootPath(): void
{
    $twig = new Environment(new ArrayLoader([
        'home.html.twig' => '<main>Welcome Home</main>',
        'page.html.twig' => '<main>{{ path }}</main>',
    ]));
    $controller = new RenderController($twig);

    $response = $controller->renderPath('/about');

    $this->assertSame(200, $response->statusCode);
    $this->assertSame('<main>/about</main>', $response->content);
}

#[Test]
public function renderPathFallsFromHomeToPageTemplate(): void
{
    $twig = new Environment(new ArrayLoader([
        'page.html.twig' => '<main>Fallback {{ path }}</main>',
    ]));
    $controller = new RenderController($twig);

    $response = $controller->renderPath('/');

    $this->assertSame(200, $response->statusCode);
    $this->assertSame('<main>Fallback /</main>', $response->content);
}
```

Add these three test methods right after the existing `renderPathFallsBackWhenNoTemplateIsFound` test (after line 48).

**Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "renderPathTriesHomeTemplate|renderPathDoesNotTryHomeTemplate|renderPathFallsFromHomeToPage" packages/ssr/tests/Unit/RenderControllerTest.php`

Expected: First and third tests FAIL (home.html.twig not in candidate list for `/`), second test PASSES.

**Step 3: Implement the template resolution change**

In `RenderController::renderPath()`, after building the `$templates` array (line 38), prepend `home.html.twig` when the normalized path is `/`:

Replace this block (lines 33-40):
```php
// Try path-specific template first (e.g., /language → language.html.twig).
$templates = [];
$pathTemplate = $this->pathSegmentToTemplate(trim($normalizedPath, '/'));
if ($pathTemplate !== null) {
    $templates[] = $pathTemplate;
}
$templates[] = 'page.html.twig';
$templates[] = 'ssr/page.html.twig';
```

With:
```php
// Try path-specific template first (e.g., /language → language.html.twig).
$templates = [];
if ($normalizedPath === '/') {
    $templates[] = 'home.html.twig';
}
$pathTemplate = $this->pathSegmentToTemplate(trim($normalizedPath, '/'));
if ($pathTemplate !== null) {
    $templates[] = $pathTemplate;
}
$templates[] = 'page.html.twig';
$templates[] = 'ssr/page.html.twig';
```

**Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/ssr/tests/Unit/RenderControllerTest.php`

Expected: ALL tests pass, including existing ones.

**Step 5: Commit**

```
feat: add home.html.twig template resolution for root path

RenderController now tries home.html.twig first when rendering the `/`
path, falling back to page.html.twig as before.
```

---

### Task 2: Create the welcome page template

**Files:**
- Create: `packages/ssr/templates/home.html.twig`
- Create: `skeleton/templates/home.html.twig`

**Step 1: Create the framework-level welcome template**

Create `packages/ssr/templates/home.html.twig` — a self-contained HTML page with inline CSS and Google Fonts (Inter). Requirements from the design doc:

- **Hero section**: "WAASEYAA" wordmark in spaced letter tracking, tagline "A light for the modern web", feature pills (PHP 8.3+, 7-Layer Architecture, JSON:API, Entity System, AI-Ready Pipeline)
- **Background**: Dark charcoal (#111827) with subtle radial amber glow (#F59E0B at low opacity) behind the wordmark
- **Quick links section**: Three cards — Admin SPA (`/admin`), API (`/api`), Documentation (link to GitHub repo)
- **Getting started section**: CLI commands in styled code blocks (`bin/waaseyaa serve`, `bin/waaseyaa entity:create`, `bin/waaseyaa config:export`), configuration pointers (WAASEYAA_DB, config/sync/, templates/)
- **Footer**: "To replace this page, edit or delete templates/home.html.twig", GitHub link
- **Typography**: Inter from Google Fonts
- **Colors**: Amber accent (#F59E0B), charcoal background (#111827), white/cream text
- **Responsive**: Works on mobile and desktop
- **No JavaScript required**

Use the `frontend-design` skill to build this template. The template receives `{{ title }}` and `{{ path }}` as Twig variables but likely won't need them.

**Step 2: Copy to skeleton**

Copy `packages/ssr/templates/home.html.twig` to `skeleton/templates/home.html.twig`. These should be identical — the skeleton copy is what ships with new apps, the SSR package copy is the framework fallback.

**Step 3: Visual verification**

Start the dev server and verify the page renders correctly:

Run: `php -S localhost:8080 -t public public/index.php`

Open `http://localhost:8080/` — should show the branded welcome page.
Open `http://localhost:8080/about` — should show the generic page.html.twig, NOT the welcome page.

**Step 4: Commit**

```
feat: add branded welcome page for new Waaseyaa apps

Self-contained homepage template with framework branding, quick links
(Admin SPA, API, docs), getting-started CLI commands, and configuration
tips. Uses Inter font via Google Fonts, inline CSS, no JS.
```

---

### Task 3: Run full test suite and verify no regressions

**Files:** None (verification only)

**Step 1: Run the full SSR unit test suite**

Run: `./vendor/bin/phpunit packages/ssr/tests/Unit/`

Expected: All tests pass.

**Step 2: Run the SSR integration tests**

Run: `./vendor/bin/phpunit tests/Integration/Phase9/`

Expected: All tests pass.

**Step 3: Run the full test suite**

Run: `./vendor/bin/phpunit`

Expected: All tests pass. No regressions.
