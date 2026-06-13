# Admin SPA CI/CD Build & Distribution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship pre-built Nuxt 4 admin SPA assets inside the `waaseyaa/admin-surface` Composer package so `create-project` installs have a working `/admin` without Node.js.

**Architecture:** CI builds the Nuxt SPA on admin source changes, copies output to `packages/admin-surface/dist/`, commits via PR. The PHP service provider adds a vendor-fallback lookup so the SPA is served from `vendor/waaseyaa/admin-surface/dist/` when no app-level `public/admin/index.html` exists. The existing splitsh-lite pipeline distributes the assets via Packagist.

**Tech Stack:** GitHub Actions, Node 22, Nuxt 4, PHP 8.4, splitsh-lite (existing)

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `packages/admin-surface/src/AdminSurfaceServiceProvider.php` | Modify | Add vendor `dist/` fallback in SPA catch-all controller |
| `packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php` | Modify | Add test for vendor dist fallback behavior |
| `.gitignore` | Modify | Allow `packages/admin-surface/dist/` to be tracked |
| `.github/workflows/admin-dist.yml` | Create | CI workflow: build SPA, commit to branch, open PR |

---

### Task 1: Add vendor dist fallback to AdminSurfaceServiceProvider

**Files:**
- Modify: `packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php`
- Modify: `packages/admin-surface/src/AdminSurfaceServiceProvider.php`

- [ ] **Step 1: Write failing test for vendor dist fallback**

Add a new test method to `AdminSurfaceServiceProviderTest` that verifies the controller closure serves `index.html` from a simulated vendor `dist/` directory when `public/admin/index.html` is absent.

The current controller closure is defined inline inside `routes()` and captures `$projectRoot`. To test the two-tier fallback without booting the full kernel, we extract the resolution logic into a static method.

Add this test after the existing `adminSpaServesIndexHtmlWhenPresent` test:

```php
#[Test]
public function adminSpaServesVendorDistWhenPublicAdminMissing(): void
{
    $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
    mkdir($tempDir . '/public', 0777, true);

    // Simulate vendor dist — create a fake dist/index.html relative to the class file.
    // Instead, test the static helper directly.
    $distHtml = '<html><body>Vendor Admin SPA</body></html>';

    try {
        $result = AdminSurfaceServiceProvider::resolveAdminIndex(
            $tempDir,
            $distHtml,
        );

        $this->assertSame($distHtml, $result);
    } finally {
        rmdir($tempDir . '/public');
        rmdir($tempDir);
    }
}

#[Test]
public function adminSpaServesPublicOverVendorDist(): void
{
    $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
    mkdir($tempDir . '/public/admin', 0777, true);
    $publicHtml = '<html><body>App Admin SPA</body></html>';
    file_put_contents($tempDir . '/public/admin/index.html', $publicHtml);

    try {
        $result = AdminSurfaceServiceProvider::resolveAdminIndex(
            $tempDir,
            '<html><body>Vendor Fallback</body></html>',
        );

        $this->assertSame($publicHtml, $result);
    } finally {
        unlink($tempDir . '/public/admin/index.html');
        rmdir($tempDir . '/public/admin');
        rmdir($tempDir . '/public');
        rmdir($tempDir);
    }
}

#[Test]
public function adminSpaReturnsNullWhenNeitherExists(): void
{
    $tempDir = sys_get_temp_dir() . '/waaseyaa_test_spa_' . uniqid();
    mkdir($tempDir . '/public', 0777, true);

    try {
        $result = AdminSurfaceServiceProvider::resolveAdminIndex(
            $tempDir,
            null,
        );

        $this->assertNull($result);
    } finally {
        rmdir($tempDir . '/public');
        rmdir($tempDir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php --filter "vendorDist|PublicOver|NeitherExists"`

Expected: FAIL — `resolveAdminIndex` method does not exist.

- [ ] **Step 3: Add resolveAdminIndex static method and update controller closure**

In `AdminSurfaceServiceProvider.php`, add a public static method and update the SPA catch-all controller to use it:

```php
/**
 * Resolve the admin SPA index.html content.
 *
 * Two-tier lookup:
 * 1. App override: $projectRoot/public/admin/index.html
 * 2. Vendor fallback: pre-built dist shipped with this package
 *
 * @param string $projectRoot Application root directory
 * @param string|null $vendorDistContent Pre-read vendor dist content (null if file missing)
 * @return string|null HTML content or null if neither source exists
 */
public static function resolveAdminIndex(string $projectRoot, ?string $vendorDistContent): ?string
{
    $appIndex = $projectRoot . '/public/admin/index.html';
    if (is_file($appIndex)) {
        return file_get_contents($appIndex);
    }

    return $vendorDistContent;
}
```

Then update the controller closure in `routes()` to use it:

Replace the existing controller closure (lines 66-79) with:

```php
->controller(static function (mixed $request = null, string $path = '') use ($projectRoot): Response {
    $vendorDist = __DIR__ . '/../dist/index.html';
    $vendorContent = is_file($vendorDist) ? file_get_contents($vendorDist) : null;

    $html = self::resolveAdminIndex($projectRoot, $vendorContent);
    if ($html !== null) {
        return new Response(
            $html,
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    $appName = getenv('APP_NAME');
    $appName = is_string($appName) && $appName !== '' ? $appName : 'Application';

    return AdminSpaFallback::htmlResponse($appName);
})
```

Note: The closure uses `self::` which works because the closure is defined inside the class method. The `static function` keyword prevents `$this` binding but `self::` resolves at definition time.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php`

Expected: All tests pass, including the three new ones and the existing ones.

- [ ] **Step 5: Commit**

```bash
git add packages/admin-surface/src/AdminSurfaceServiceProvider.php packages/admin-surface/tests/Unit/AdminSurfaceServiceProviderTest.php
git commit -m "feat(admin-surface): add vendor dist fallback for pre-built SPA assets

The SPA catch-all controller now checks vendor dist/index.html when
public/admin/index.html is absent, enabling create-project installs
to serve the admin SPA without Node.js.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Update .gitignore to allow admin-surface/dist/

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Add exception for admin-surface dist directory**

After the existing `packages/admin/.output/` line in `.gitignore`, add:

```gitignore
# Admin SPA pre-built distribution (committed by CI)
!packages/admin-surface/dist/
```

- [ ] **Step 2: Verify git will track files in dist/**

Run: `mkdir -p packages/admin-surface/dist && echo "test" > packages/admin-surface/dist/test.txt && git status packages/admin-surface/dist/ && rm packages/admin-surface/dist/test.txt && rmdir packages/admin-surface/dist`

Expected: `packages/admin-surface/dist/test.txt` appears as untracked (not ignored).

- [ ] **Step 3: Commit**

```bash
git add .gitignore
git commit -m "chore: allow packages/admin-surface/dist/ to be tracked by git

CI will commit pre-built Nuxt SPA assets to this directory.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Create admin-dist GitHub Actions workflow

**Files:**
- Create: `.github/workflows/admin-dist.yml`

- [ ] **Step 1: Write the workflow file**

```yaml
name: Admin SPA Distribution Build

on:
  push:
    branches: [main]
    paths:
      - 'packages/admin/**'
      - '.github/workflows/admin-dist.yml'
  workflow_dispatch:

permissions:
  contents: write
  pull-requests: write

concurrency:
  group: admin-dist
  cancel-in-progress: true

jobs:
  build-and-publish:
    name: Build admin SPA and open dist PR
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
        with:
          fetch-depth: 0
          token: ${{ secrets.GITHUB_TOKEN }}

      - name: Set up Node.js
        uses: actions/setup-node@v6
        with:
          node-version: '22'
          cache: 'npm'
          cache-dependency-path: packages/admin/package-lock.json

      - name: Install dependencies
        run: cd packages/admin && npm ci

      - name: Build Nuxt SPA
        run: cd packages/admin && npm run build

      - name: Copy build output to admin-surface/dist
        run: |
          rm -rf packages/admin-surface/dist
          cp -r packages/admin/.output/public packages/admin-surface/dist

      - name: Check for changes
        id: diff
        run: |
          git add packages/admin-surface/dist/
          if git diff --cached --quiet packages/admin-surface/dist/; then
            echo "changed=false" >> "$GITHUB_OUTPUT"
          else
            echo "changed=true" >> "$GITHUB_OUTPUT"
          fi

      - name: Commit and push
        if: steps.diff.outputs.changed == 'true'
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git checkout -B admin-dist/update
          git commit -m "chore(admin-surface): update pre-built SPA dist

          Built from $(git rev-parse --short HEAD) by admin-dist workflow.

          Co-Authored-By: github-actions[bot] <41898282+github-actions[bot]@users.noreply.github.com>"
          git push --force-with-lease origin admin-dist/update

      - name: Create or update PR
        if: steps.diff.outputs.changed == 'true'
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          EXISTING_PR=$(gh pr list --head admin-dist/update --json number --jq '.[0].number // empty')
          if [ -n "$EXISTING_PR" ]; then
            echo "PR #${EXISTING_PR} already exists — push updated it."
          else
            gh pr create \
              --title "chore(admin-surface): update pre-built SPA dist" \
              --body "$(cat <<'EOF'
          ## Summary

          - Rebuilt Nuxt 4 admin SPA from latest `packages/admin/` source
          - Updated `packages/admin-surface/dist/` with production build output
          - Assets ship via Composer so `create-project` installs work without Node.js

          ## Test plan

          - [ ] Verify `packages/admin-surface/dist/index.html` exists and is valid HTML
          - [ ] `composer create-project` install serves `/admin` from vendor dist
          - [ ] `/admin/_surface/*` API endpoints still work

          Automated by `admin-dist.yml` workflow.
          EOF
          )" \
              --head admin-dist/update \
              --base main
          fi

      - name: Skip — no changes
        if: steps.diff.outputs.changed == 'false'
        run: echo "Admin SPA dist is already up to date."
```

- [ ] **Step 2: Validate YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/admin-dist.yml'))" && echo "YAML valid"`

Expected: `YAML valid`

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/admin-dist.yml
git commit -m "ci: add admin SPA distribution build workflow

Builds the Nuxt 4 admin SPA on admin source changes, copies output
to packages/admin-surface/dist/, and opens a PR. Assets ship via
the existing splitsh-lite pipeline to Packagist.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Verify full pipeline locally

**Files:**
- None (verification only)

- [ ] **Step 1: Run the full test suite for admin-surface**

Run: `./vendor/bin/phpunit packages/admin-surface/tests/`

Expected: All tests pass.

- [ ] **Step 2: Run PHPStan**

Run: `composer phpstan`

Expected: No new errors.

- [ ] **Step 3: Run code style check**

Run: `composer cs-check`

Expected: No violations in modified files.

- [ ] **Step 4: Simulate the CI build locally**

Run:
```bash
cd packages/admin && npm ci && npm run build
rm -rf ../admin-surface/dist
cp -r .output/public ../admin-surface/dist
ls ../admin-surface/dist/index.html
```

Expected: `packages/admin-surface/dist/index.html` exists.

- [ ] **Step 5: Verify the vendor fallback serves the built SPA**

The test from Task 1 covers this programmatically. Additionally, verify the file structure:

Run: `head -5 packages/admin-surface/dist/index.html`

Expected: Valid HTML with Nuxt SPA markup.

- [ ] **Step 6: Clean up local build artifacts**

Run: `rm -rf packages/admin-surface/dist packages/admin/.output`

The CI workflow will commit the actual dist — local artifacts are just for verification.
