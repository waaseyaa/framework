# Skeleton DX & Welcome Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer create-project waaseyaa/waaseyaa` produce a project that boots cleanly with zero manual setup, and replace the current welcome page with an ultra-minimalistic greeting that cycles through "Ahnii" and greetings from around the world in their native scripts.

**Architecture:** Two independent changes to the skeleton directory (`skeleton/`). (1) Add `.env.example` and a PHP post-create script that copies it and generates a JWT secret. (2) Replace `templates/home.html.twig` with a minimal centered greeting animation page. Both changes are synced to the `waaseyaa/waaseyaa` repo by the existing `sync-skeleton.yml` workflow on tag push.

**Tech Stack:** PHP 8.4, Twig, vanilla CSS animations, Composer scripts

---

### Task 1: Add `.env.example` and post-create setup script

**Files:**
- Create: `skeleton/.env.example`
- Create: `skeleton/bin/post-create-setup.php`
- Modify: `skeleton/composer.json` (add post-create-project-cmd entries)

- [ ] **Step 1: Create `.env.example`**

```env
APP_NAME=Waaseyaa
WAASEYAA_DB=./waaseyaa.sqlite
WAASEYAA_JWT_SECRET=
WAASEYAA_DEV_FALLBACK_ACCOUNT=true
```

- [ ] **Step 2: Create `bin/post-create-setup.php`**

PHP script that:
1. Copies `.env.example` to `.env` if `.env` doesn't exist
2. Generates a random 32-byte hex JWT secret and writes it into `.env`
3. Prints a welcome banner with next steps

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envExample = $root . '/.env.example';
$envFile = $root . '/.env';

if (!file_exists($envFile) && file_exists($envExample)) {
    $content = file_get_contents($envExample);
    $secret = bin2hex(random_bytes(32));
    $content = str_replace('WAASEYAA_JWT_SECRET=', "WAASEYAA_JWT_SECRET={$secret}", $content);
    file_put_contents($envFile, $content);
}

echo "\n";
echo "  \033[32m✓ Waaseyaa project ready!\033[0m\n";
echo "\n";
echo "  Next steps:\n";
echo "    \033[33mbin/waaseyaa serve\033[0m    Start the dev server\n";
echo "    \033[33mbin/waaseyaa\033[0m          See all available commands\n";
echo "\n";
```

- [ ] **Step 3: Update `skeleton/composer.json` scripts**

Add the setup script after the existing chmod:

```json
"post-create-project-cmd": [
    "chmod +x bin/waaseyaa",
    "php bin/post-create-setup.php"
]
```

- [ ] **Step 4: Add `.env` to `skeleton/.gitignore`**

Ensure `.env` is gitignored (should not be committed by users) but `.env.example` is tracked.

- [ ] **Step 5: Test locally**

```bash
rm -rf /home/jones/dev/irc.northcloud.one
cd /home/jones/dev/waaseyaa/skeleton
php bin/post-create-setup.php
# Verify .env was created with a JWT secret
cat .env
rm .env  # clean up
```

- [ ] **Step 6: Commit**

```bash
git add skeleton/.env.example skeleton/bin/post-create-setup.php skeleton/composer.json skeleton/.gitignore
git commit -m "feat: add post-create-project setup with .env generation and JWT secret"
```

---

### Task 2: Replace welcome page with minimalistic greeting animation

**Files:**
- Modify: `skeleton/templates/home.html.twig` (complete rewrite)

- [ ] **Step 1: Write the new `home.html.twig`**

Ultra-minimal design:
- Dark background (#111827), centered vertically
- Large greeting text that smoothly fades/slides between greetings
- Ahnii always appears first as a tribute to the project's Indigenous roots
- After Ahnii, remaining greetings are shuffled randomly on page load
- Cycles through all greetings before reshuffling and repeating
- Each greeting uses its native script with proper `dir="rtl"` for Arabic/Hebrew/Persian/Urdu
- Below greeting: tagline "A light for the modern web" in small muted text
- Below tagline: three minimal text links — Docs · API · Admin
- Footer: one line about editing the template
- Minimal vanilla JS for shuffle + cycle logic; CSS handles the fade transition
- Responsive

Greeting list (exhaustive, native scripts):
- Ahnii (Anishinaabemowin), Hello (English), Bonjour (French), Hola (Spanish), 你好 (Mandarin), こんにちは (Japanese), 안녕하세요 (Korean), नमस्ते (Hindi), สวัสดี (Thai), Xin chào (Vietnamese), مرحبا (Arabic), שלום (Hebrew), سلام (Persian), Привет (Russian), Вітаю (Ukrainian), Cześć (Polish), Ahoj (Czech), Hej (Swedish), Hei (Norwegian), Hej (Danish), Moi (Finnish), Halló (Icelandic), Γεια σου (Greek), Merhaba (Turkish), Sawubona (Zulu), Habari (Swahili), Salama (Malagasy), Aloha (Hawaiian), Kia ora (Māori), Bula (Fijian), Talofa (Samoan), Hallo (German), Hallo (Dutch), Olá (Portuguese), Ciao (Italian), Salut (Romanian), Здраво (Serbian), Здравей (Bulgarian), Sveiki (Latvian), Labas (Lithuanian), Tere (Estonian), Szia (Hungarian), Kamusta (Filipino), Selamat (Malay/Indonesian), ស្វស្តី (Khmer), สบายดี (Lao), မင်္ဂလာပါ (Burmese), བཀྲ་ཤིས་བདེ་ལེགས (Tibetan), ᓄᓇᕗᑦ (Inuktitut), Osiyo (Cherokee), Hau (Lakota), Yá'át'ééh (Navajo), Tansi (Cree), Kwey (Algonquin)

Implementation uses CSS `@keyframes` with `animation-delay` offsets on stacked `<span>` elements. Each greeting fades in, holds, fades out. Total cycle ~60-90 seconds before repeating.

- [ ] **Step 2: Start dev server and verify in browser**

```bash
cd /home/jones/dev/waaseyaa/skeleton
WAASEYAA_DEV_FALLBACK_ACCOUNT=true php -S localhost:8080 -t public
```

Navigate to http://localhost:8080 — verify:
- Ahnii appears first
- Greetings cycle smoothly
- RTL greetings display correctly
- Links work
- Responsive on mobile viewport

- [ ] **Step 3: Commit**

```bash
git add skeleton/templates/home.html.twig
git commit -m "feat: replace welcome page with minimalistic Ahnii greeting animation"
```

---

### Task 3: Tag and release

- [ ] **Step 1: Push and tag**

```bash
git push origin main
git tag v0.1.0-alpha.47
git push origin v0.1.0-alpha.47
```

- [ ] **Step 2: Wait for split workflow to complete**

- [ ] **Step 3: End-to-end verification**

```bash
rm -rf /home/jones/dev/irc.northcloud.one
composer create-project waaseyaa/waaseyaa irc.northcloud.one --stability=alpha
cd irc.northcloud.one
# Verify .env exists with JWT secret
cat .env
# Verify serve works
bin/waaseyaa serve
# Browse to http://localhost:8080 — greeting animation works
# Browse to http://localhost:8080/api — JSON:API discovery works
```
