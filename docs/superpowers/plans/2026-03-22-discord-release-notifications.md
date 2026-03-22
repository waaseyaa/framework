# Discord Release Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send a rich Discord embed with changelog and milestone info whenever a `v*` tag is pushed.

**Architecture:** Standalone GitHub Actions workflow (`.github/workflows/discord-release.yml`) triggered by tag pushes. Three steps: checkout, generate changelog via shell, send embed via `actions/github-script`. Best-effort — never blocks releases.

**Tech Stack:** GitHub Actions, `actions/github-script@v7` (Node 20 `fetch()`), Discord webhook API

**Spec:** `docs/superpowers/specs/2026-03-22-discord-release-notifications-design.md`

---

### Task 1: Create the workflow file with trigger and checkout

**Files:**
- Create: `.github/workflows/discord-release.yml`

- [ ] **Step 1: Create the workflow file**

```yaml
name: Discord Release Notification

on:
  push:
    tags: ['v*']

permissions:
  contents: read

jobs:
  notify:
    name: Send Discord notification
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
```

- [ ] **Step 2: Validate YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/discord-release.yml'))"`
Expected: No output (valid YAML)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/discord-release.yml
git commit -m "feat: add Discord release notification workflow scaffold"
```

---

### Task 2: Add changelog generation step

**Files:**
- Modify: `.github/workflows/discord-release.yml`

- [ ] **Step 1: Add the changelog generation step after checkout**

Append this step to the `steps:` array:

```yaml
      - name: Generate changelog
        id: changelog
        run: |
          TAG="${{ github.ref_name }}"
          PREV_TAG=$(git tag --sort=-version:refname | grep -v "^${TAG}$" | head -1)

          if [ -z "$PREV_TAG" ]; then
            CHANGELOG="Initial release"
          else
            CHANGELOG=$(git log --oneline "${PREV_TAG}..${TAG}" 2>/dev/null || echo "No changes recorded")
          fi

          if [ -z "$CHANGELOG" ]; then
            CHANGELOG="No changes recorded"
          fi

          # Truncate to 1900 chars for Discord readability
          if [ ${#CHANGELOG} -gt 1900 ]; then
            CHANGELOG="${CHANGELOG:0:1897}..."
          fi

          {
            echo "CHANGELOG<<CHANGELOG_EOF"
            echo "$CHANGELOG"
            echo "CHANGELOG_EOF"
            echo "PREV_TAG=${PREV_TAG:-none}"
          } >> "$GITHUB_ENV"
```

- [ ] **Step 2: Validate YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/discord-release.yml'))"`
Expected: No output (valid YAML)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/discord-release.yml
git commit -m "feat: add changelog generation step to Discord notification"
```

---

### Task 3: Add Discord embed step

**Files:**
- Modify: `.github/workflows/discord-release.yml`

- [ ] **Step 1: Add the Discord notification step after changelog**

Append this step to the `steps:` array:

```yaml
      - name: Send Discord notification
        if: ${{ !cancelled() }}
        uses: actions/github-script@v7
        env:
          DISCORD_WEBHOOK_URL: ${{ secrets.DISCORD_WEBHOOK_URL }}
        with:
          script: |
            const webhookUrl = process.env.DISCORD_WEBHOOK_URL;
            if (!webhookUrl) {
              core.warning('DISCORD_WEBHOOK_URL secret not set — skipping notification');
              return;
            }

            const tag = '${{ github.ref_name }}';
            const repo = '${{ github.repository }}';
            const changelog = process.env.CHANGELOG || 'No changes recorded';
            const prevTag = process.env.PREV_TAG || 'none';

            // Fetch active milestones for footer
            let milestoneText = '';
            try {
              const { data: milestones } = await github.rest.issues.listMilestones({
                owner: context.repo.owner,
                repo: context.repo.repo,
                state: 'open',
                sort: 'due_on',
                per_page: 3,
              });
              if (milestones.length > 0) {
                milestoneText = milestones.map(m => {
                  const open = m.open_issues;
                  const closed = m.closed_issues;
                  const total = open + closed;
                  const pct = total > 0 ? Math.round((closed / total) * 100) : 0;
                  return `${m.title}: ${pct}%`;
                }).join(' · ');
              }
            } catch (e) {
              core.warning(`Failed to fetch milestones: ${e.message}`);
            }

            const embed = {
              title: `🚀 ${tag}`,
              description: `\`\`\`\n${changelog}\n\`\`\``,
              url: `https://github.com/${repo}/releases/tag/${tag}`,
              color: 0x2ecc71,
              footer: { text: milestoneText || 'Waaseyaa Framework' },
              timestamp: new Date().toISOString(),
            };

            if (prevTag !== 'none') {
              embed.fields = [{
                name: 'Diff',
                value: `[${prevTag}...${tag}](https://github.com/${repo}/compare/${prevTag}...${tag})`,
                inline: true,
              }];
            }

            const payload = {
              username: 'Waaseyaa Releases',
              embeds: [embed],
            };

            try {
              const response = await fetch(webhookUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
              });
              if (!response.ok) {
                core.warning(`Discord webhook returned ${response.status}: ${await response.text()}`);
              } else {
                core.info(`Discord notification sent for ${tag}`);
              }
            } catch (e) {
              core.warning(`Failed to send Discord notification: ${e.message}`);
            }
```

- [ ] **Step 2: Validate YAML syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/discord-release.yml'))"`
Expected: No output (valid YAML)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/discord-release.yml
git commit -m "feat: add Discord embed notification step with milestone info"
```

---

### Task 4: Manual smoke test

- [ ] **Step 1: Review the complete workflow file**

Run: `cat .github/workflows/discord-release.yml`
Verify: All 3 steps present (checkout, changelog, notification), permissions set, secrets via env block.

- [ ] **Step 2: Push to main and create a test tag**

```bash
git push origin main
git tag v0.1.0-alpha.40
git push origin v0.1.0-alpha.40
```

- [ ] **Step 3: Verify the workflow runs**

Run: `gh run list --workflow=discord-release.yml --limit=1`
Expected: A run triggered by the tag push, status "completed" with conclusion "success".

- [ ] **Step 4: Verify the Discord message**

Check the Discord channel for a green embed with:
- Title: `🚀 v0.1.0-alpha.40`
- Changelog in a code block
- Diff link to compare with previous tag
- Milestone progress in the footer

- [ ] **Step 5: If notification failed, check logs**

Run: `gh run view --log --workflow=discord-release.yml | grep -i -A5 "discord\|warning\|error"`
Fix any issues and re-tag if needed.
