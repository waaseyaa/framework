# Packagist POC Playbook

> **Authorization level:** Decision Point #1 approved. Decision Point #2 REQUIRED before
> executing any step that creates mirror repos or touches Packagist.
>
> **Scope:** 3 packages — `waaseyaa/foundation`, `waaseyaa/entity`, `waaseyaa/api`

---

## Prerequisites

Before beginning the POC sprint, confirm:

- [ ] Russell has approved Decision Point #2 (mirror repo creation)
- [ ] GitHub org `waaseyaa` has permission to create new repositories
- [ ] `SPLIT_GITHUB_TOKEN` secret created in `waaseyaa/framework` repository settings
  - Token needs: `repo` scope for the `waaseyaa` org (to push to mirror repos)
  - Settings → Secrets and variables → Actions → New repository secret

---

## Phase 1: Install splitsh-lite (local)

```bash
# Download splitsh-lite binary
curl -sL https://github.com/splitsh/lite/releases/latest/download/lite_linux_amd64.tar.gz \
  | tar xz -C /usr/local/bin/
chmod +x /usr/local/bin/splitsh-lite
splitsh-lite --version
```

**Dry-run first (safe — no external effects):**
```bash
bash scripts/splitsh-lite-simulate.sh packages/foundation foundation
bash scripts/splitsh-lite-simulate.sh packages/entity entity
bash scripts/splitsh-lite-simulate.sh packages/api api
```
Expected: commit lists printed, no changes made.

---

## Phase 2: Normalize @dev constraints for POC packages

⚠️ Do NOT commit these changes to `main` or `develop/v1.1`. Use a temporary branch.

```bash
git checkout -b poc/packagist-normalize develop/v1.1

RELEASE="1.1"
for pkg in foundation entity api; do
  # Replace @dev with ^1.1 for waaseyaa/* dependencies only
  sed -i "s/\"waaseyaa\/\([^\"]*\)\": \"@dev\"/\"waaseyaa\/\1\": \"^${RELEASE}\"/g" \
    packages/$pkg/composer.json
done

# Verify no @dev constraints remain in the 3 POC packages
grep '"@dev"' packages/foundation/composer.json packages/entity/composer.json packages/api/composer.json
# Expected: no output (grep exits 1 = no matches = good)

# Validate all three
composer validate packages/foundation/composer.json --strict
composer validate packages/entity/composer.json --strict
composer validate packages/api/composer.json --strict
```

---

## Phase 3: Create mirror repos (requires Decision Point #2 approval)

```bash
# Only run after Russell approves Decision Point #2

gh repo create waaseyaa/foundation --public \
  --description "Waaseyaa framework: foundation package (Layer 0)"
gh repo create waaseyaa/entity --public \
  --description "Waaseyaa framework: entity type system (Layer 1)"
gh repo create waaseyaa/api --public \
  --description "Waaseyaa framework: JSON:API controller and routing (Layer 4)"
```

---

## Phase 4: Run the split

```bash
# Must be on a tagged commit
git tag v1.1.0-poc  # POC test tag — do NOT use for production

for pkg in foundation entity api; do
  SHA=$(splitsh-lite --prefix=packages/$pkg)
  echo "Split SHA for $pkg: $SHA"
  # Push to mirror (requires SPLIT_GITHUB_TOKEN in env)
  GITHUB_TOKEN=$SPLIT_GITHUB_TOKEN \
    git push https://github.com/waaseyaa/$pkg.git "$SHA:refs/heads/main"
  git push https://github.com/waaseyaa/$pkg.git v1.1.0-poc
done
```

---

## Phase 5: Register on Packagist

1. Log in to https://packagist.org with the `waaseyaa` account
2. Go to https://packagist.org/packages/submit
3. Submit each mirror URL:
   - `https://github.com/waaseyaa/foundation`
   - `https://github.com/waaseyaa/entity`
   - `https://github.com/waaseyaa/api`
4. Enable GitHub webhook on each mirror repo (Packagist provides the URL)

---

## Phase 6: Consumer verification (Decision Point #3)

```bash
# Create fresh test project
mkdir /tmp/waaseyaa-consumer-poc && cd /tmp/waaseyaa-consumer-poc
composer init --no-interaction --name="test/waaseyaa-poc" --stability=stable

# Install from Packagist
composer require waaseyaa/api:^1.1

# Smoke test autoloader
php -r "
  require 'vendor/autoload.php';
  \$classes = [
    'Waaseyaa\\\\Api\\\\JsonApiController',
    'Waaseyaa\\\\Entity\\\\EntityType',
    'Waaseyaa\\\\Foundation\\\\Kernel\\\\AbstractKernel',
  ];
  foreach (\$classes as \$c) {
    echo class_exists(\$c) ? \"PASS: \$c\\n\" : \"FAIL: \$c\\n\";
  }
"
# Expected: PASS for all three
```

---

## Acceptance Criteria

| Check | Command | Expected |
|---|---|---|
| Packagist lists foundation | `curl -s https://packagist.org/packages/waaseyaa/foundation.json \| jq '.package.name'` | `"waaseyaa/foundation"` |
| Latest version indexed | `curl -s https://packagist.org/packages/waaseyaa/api.json \| jq '.package.versions \| keys \| .[0]'` | `"v1.1.0-poc"` |
| Consumer install exit 0 | `composer require waaseyaa/api:^1.1; echo $?` | `0` |
| Autoloader smoke test | see Phase 6 | `PASS` × 3 |
| No @dev in published package | `curl https://raw.githubusercontent.com/waaseyaa/entity/main/composer.json \| jq '.require'` | no `@dev` values |

---

## Secrets Required

| Secret name | Scope | Where to set |
|---|---|---|
| `SPLIT_GITHUB_TOKEN` | `repo` on `waaseyaa` org | `waaseyaa/framework` → Settings → Secrets |
| Packagist API token | Packagist account | Local env only (for yank/update commands) |

---

## Rollback (if POC fails)

```bash
# Delete the POC tag (it was created only for POC, not a production tag)
git push origin :refs/tags/v1.1.0-poc
git tag -d v1.1.0-poc

# Remove POC mirror repos if needed
gh repo delete waaseyaa/foundation --yes
gh repo delete waaseyaa/entity --yes
gh repo delete waaseyaa/api --yes

# Restore @dev constraints in the 3 packages
git checkout develop/v1.1 -- packages/foundation/composer.json
git checkout develop/v1.1 -- packages/entity/composer.json
git checkout develop/v1.1 -- packages/api/composer.json
```

> The POC tag `v1.1.0-poc` is exclusively for the proof of concept.
> Production tags (`v1.0.0-final`, `v1.1.0`) are not touched.
