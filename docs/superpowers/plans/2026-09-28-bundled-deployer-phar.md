# Bundled Deployer phar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the official Deployer phar inside `heimrichhannot/deployer-recipes` as `vendor/bin/dep`, drop `deployer/deployer` as a composer dependency, and keep the bundled phar up to date with a scheduled GitHub workflow that releases automatically.

**Architecture:** The unmodified upstream phar and its signature are committed to `bin/`, and a three-line wrapper `bin/dep` is exposed through composer's `bin`. `composer.json` `extra.deployer.version` records the bundled version and drives a runtime major-version guard in `autoload.php`. A daily workflow detects new upstream releases (pure PHP decision logic plus thin shell scripts), verifies the GPG signature, runs smoke tests, and then either commits, tags and releases (same Deployer major) or opens a PR (new Deployer major, or unreleased changes on `main`).

**Tech Stack:** PHP 8.3, Composer 2, PHPUnit 11.5, Bash, GnuPG, GitHub Actions (`actions/checkout@v4`, `shivammathur/setup-php@v2`), `gh` CLI, Docker (local test runs only).

**Spec:** `docs/superpowers/specs/2026-09-28-bundled-deployer-phar-design.md`

All code in this plan was prototyped and run before the plan was written: PHPUnit (32 tests), the smoke fixtures under PHP 8.3, the three script tests and actionlint on all three workflows.

## Deviations from the spec (decided while planning)

1. **`bin/deployer.phar.asc` is committed next to the phar**, so CI re-verifies the committed phar on every push. It is export-ignored, so consuming projects don't get it.
2. **`extra.branch-alias.dev-main` (`2.x-dev`) records the recipes major version.** The update workflow needs it to find the latest tag in the current recipes major (the spec's "tag guard"). Deriving it from the latest tag would pick `1.x` before `2.0.0` exists and release a 1.x version bundling Deployer 8.
3. **The README doesn't pin `^2.0`.** Commit `6ee85ce` ("Drop major version framing from docs") established that release versions are decided at tag time, so the docs install without a constraint and the CHANGELOG stays `[Unreleased]`.

## Global Constraints

- PHP floor: `php: ^8.3` in `composer.json`. `config.platform.php` is `8.3.0`, and every local test run uses the `php:8.3-cli` image.
- Initially bundled Deployer version: `8.0.5`. `composer.json` `extra.deployer.version` is the single machine-readable record of it.
- Deployer sign key fingerprint: `0C331EAD47B77AC7DCA31D99EFC847736630CF36` ("Anton Medvedev (Deployer Sign Key) <anton@deployer.org>", from keys.openpgp.org).
- Tags of this repository have no `v` prefix (`1.9.2`, `2.0.0`). Upstream tags do (`v8.0.5`).
- No release version numbers in README or CHANGELOG; `[Unreleased]` stays as is. The only exception is `branch-alias` `2.x-dev`.
- The host has **no PHP**. Run PHP through Docker from the repository root, with these exact prefixes:
  - **`php83`** = `docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app php:8.3-cli php`
  - **`in-php83`** = `docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app php:8.3-cli` (runs a script inside the PHP 8.3 image)
  - **`in-composer`** = `docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2` (has git, bash, and PHP 8.5)
  - Host tools: `bash`, `curl`, `gpg`, `git`, `gh`, `docker`.
- Every commit message ends with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Commit on `feature/deployer-v8`. **Never push**; the user pushes.
- Existing code style: `namespace Deployer;` in recipe files, global functions called with a leading `\` (`\defined`, `\sprintf`), 4-space indentation, `if (...)\n{` braces in `autoload.php`.

## Review Focus

1. **A tampered download, or one signed by another key**, is rejected before anything is written to `bin/` → Task 1 (`verify-phar-test.sh`: tampered phar, foreign signature, missing release).
2. **Daily re-runs with nothing new, or with a PR already open for the version**, produce no commit, no force-push and no duplicate PR → Task 5 (`testPlanIgnoresOlderAndEqualReleases`) and Task 6 (`open-pr-test.sh` cases 2 and 3).
3. **Upstream pre-releases and non-version tags** (`v8.1.0-rc.1`, `latest`, duplicates, stray whitespace) are never bundled → Task 5 (`testStableVersionsKeepsOnlyStrictVersionsSortedAscending`).
4. **A global or old `dep` loading the v2 recipes** stops with a message naming both versions and `vendor/bin/dep`, and exits 1 → Task 4 (end-to-end step with a patched `extra.deployer.version`).
5. **Git tags unavailable to the plan step** (no git, or not a checkout) makes the plan fail instead of silently reporting `untagged` → Task 6 (`deployer-update-cli-test.sh` last case). Shallow checkouts are covered by `fetch-depth: 0` in Task 7.

## File Structure

| Path | Responsibility | Task |
|---|---|---|
| `.github/deployer-sign-key.asc` | Deployer public sign key | 1 |
| `.github/scripts/verify-phar.sh` | Verify phar + signature against the pinned fingerprint | 1 |
| `.github/scripts/fetch-phar.sh` | Download a release, verify it, then move it into place | 1 |
| `tests/scripts/verify-phar-test.sh` | Tests for both scripts | 1 |
| `bin/deployer.phar`, `bin/deployer.phar.asc` | Bundled upstream phar and signature | 2 |
| `bin/dep` | Wrapper exposed as `vendor/bin/dep` | 2 |
| `composer.json`, `.gitattributes`, `.gitignore` | Package metadata and dist contents | 2 |
| `tests/smoke/{contao,symfony,typo3}/deploy.php`, `tests/smoke/run.sh` | Smoke test: phar runs, recipes load | 3 |
| `extension/VersionGuard.php`, `autoload.php` | Runtime Deployer major check | 4 |
| `phpunit.xml.dist`, `tests/bootstrap.php`, `tests/unit/VersionGuardTest.php` | PHPUnit setup and guard tests | 4 |
| `.github/scripts/DeployerUpdate.php`, `tests/unit/DeployerUpdateTest.php` | Pure update decision logic and its tests | 5 |
| `.github/scripts/deployer-update.php`, `tests/scripts/deployer-update-cli-test.sh` | CLI glue for the workflow and its test | 6 |
| `.github/scripts/open-pr.sh`, `tests/scripts/open-pr-test.sh` | Idempotent branch push + PR create/edit and its test | 6 |
| `.github/workflows/ci.yml`, `deployer-update.yml`, `release.yml` | CI, update workflow, release | 7 |
| `README.md`, `CHANGELOG.md` | Docs and upgrade notes | 8 |

---

### Task 1: Signature verification scripts

**Files:**
- Create: `.github/deployer-sign-key.asc`
- Create: `.github/scripts/verify-phar.sh`
- Create: `.github/scripts/fetch-phar.sh`
- Test: `tests/scripts/verify-phar-test.sh`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `.github/scripts/verify-phar.sh <phar> <signature>`: exits 0 only for a good signature by fingerprint `0C331EAD47B77AC7DCA31D99EFC847736630CF36`; otherwise prints a reason to stderr and exits 1.
  - `.github/scripts/fetch-phar.sh <deployer-version> <directory>`: e.g. `fetch-phar.sh 8.0.5 bin` writes `bin/deployer.phar` and `bin/deployer.phar.asc` only after verification. On any failure it exits non-zero and leaves `<directory>` untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/scripts/verify-phar-test.sh` and make it executable (`chmod +x tests/scripts/verify-phar-test.sh`):

```bash
#!/usr/bin/env bash
# Tests .github/scripts/fetch-phar.sh and verify-phar.sh against a real Deployer release (needs network and gpg).
set -euo pipefail

scripts="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

mkdir "$work/good" "$work/missing"
check 'fetches and verifies a real release' '"$scripts/fetch-phar.sh" 8.0.5 "$work/good" > /dev/null'
check 'leaves phar and signature in the directory' '[ -s "$work/good/deployer.phar" ] && [ -s "$work/good/deployer.phar.asc" ]'
check 'fails for a release that does not exist' '! "$scripts/fetch-phar.sh" 0.0.0 "$work/missing" 2> /dev/null'
check 'leaves the directory untouched on failure' '[ -z "$(ls -A "$work/missing")" ]'

cp "$work/good/deployer.phar" "$work/tampered.phar"
printf x >> "$work/tampered.phar"
check 'rejects a tampered phar' '! "$scripts/verify-phar.sh" "$work/tampered.phar" "$work/good/deployer.phar.asc" 2> /dev/null'

GNUPGHOME="$work/foreign" && mkdir -m 700 "$GNUPGHOME" && export GNUPGHOME
gpg --batch --quiet --passphrase '' --quick-gen-key 'Foreign <foreign@example.org>' default default never
gpg --batch --quiet --armor --detach-sign --output "$work/foreign.asc" "$work/good/deployer.phar"
gpgconf --kill gpg-agent
unset GNUPGHOME
check 'rejects a signature by another key' '! "$scripts/verify-phar.sh" "$work/good/deployer.phar" "$work/foreign.asc" 2> /dev/null'

exit $fails
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `tests/scripts/verify-phar-test.sh`
Expected: `FAIL: fetches and verifies a real release` and `FAIL: leaves phar and signature in the directory`; then the script stops at `cp` (there is no downloaded phar to tamper with) and exits non-zero. The scripts don't exist yet.

- [ ] **Step 3: Add the sign key**

```bash
curl --fail --silent --show-error --output .github/deployer-sign-key.asc \
  https://keys.openpgp.org/vks/v1/by-fingerprint/0C331EAD47B77AC7DCA31D99EFC847736630CF36
gpg --show-keys .github/deployer-sign-key.asc
```

Expected: `gpg --show-keys` lists `0C331EAD47B77AC7DCA31D99EFC847736630CF36` and `Anton Medvedev (Deployer Sign Key) <anton@deployer.org>`. If the fingerprint differs, stop and ask; don't continue with a different key.

- [ ] **Step 4: Write `verify-phar.sh`**

Create `.github/scripts/verify-phar.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Usage: verify-phar.sh <phar> <signature>
# Exits 0 only if <signature> is a valid signature of <phar> by the Deployer sign key.
set -euo pipefail

fingerprint=0C331EAD47B77AC7DCA31D99EFC847736630CF36
key="$(cd "$(dirname "$0")/.." && pwd)/deployer-sign-key.asc"

GNUPGHOME="$(mktemp -d)"
export GNUPGHOME
trap 'gpgconf --kill gpg-agent 2>/dev/null || true; rm -rf "$GNUPGHOME"' EXIT

gpg --batch --quiet --import "$key" 2>/dev/null

if ! status="$(gpg --batch --status-fd 1 --verify "$2" "$1" 2>/dev/null)"; then
    echo "Signature check failed: $2 is not a valid signature of $1" >&2
    exit 1
fi

# VALIDSIG's last field is the primary key fingerprint.
if ! grep -Eq "^\[GNUPG:\] VALIDSIG .* ${fingerprint}\$" <<<"$status"; then
    echo "Signature check failed: $1 is not signed by ${fingerprint}" >&2
    exit 1
fi

echo "ok: $1 is signed by the Deployer sign key"
```

- [ ] **Step 5: Write `fetch-phar.sh`**

Create `.github/scripts/fetch-phar.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Usage: fetch-phar.sh <deployer-version> <directory>
# Downloads deployer.phar and deployer.phar.asc of a Deployer release, verifies the signature and only then
# moves both files into <directory>. On any failure <directory> is left untouched.
set -euo pipefail

version=$1 directory=$2
base="https://github.com/deployphp/deployer/releases/download/v$version"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

for file in deployer.phar deployer.phar.asc; do
    curl --fail --silent --show-error --location --retry 3 --output "$work/$file" "$base/$file"
done

"$(dirname "$0")/verify-phar.sh" "$work/deployer.phar" "$work/deployer.phar.asc"

mv "$work/deployer.phar" "$work/deployer.phar.asc" "$directory/"
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `tests/scripts/verify-phar-test.sh`
Expected: six `ok:` lines, exit 0.

- [ ] **Step 7: Commit**

```bash
git add .github/deployer-sign-key.asc .github/scripts/verify-phar.sh .github/scripts/fetch-phar.sh tests/scripts/verify-phar-test.sh
git commit -m "Add scripts to fetch and verify signed Deployer phars" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Bundle the phar as `vendor/bin/dep`

**Files:**
- Create: `bin/deployer.phar`, `bin/deployer.phar.asc` (downloaded by `fetch-phar.sh`)
- Create: `bin/dep`
- Create: `.gitattributes`
- Modify: `composer.json` (whole file)
- Modify: `.gitignore` (append)

**Interfaces:**
- Consumes: `.github/scripts/fetch-phar.sh <version> <directory>` (Task 1).
- Produces:
  - `bin/dep`, which runs the bundled Deployer; consuming projects get it as `vendor/bin/dep`.
  - `composer.json` `extra.deployer.version` = `"8.0.5"`.
  - `composer.json` `extra.branch-alias.dev-main` = `"2.x-dev"`.
  - `require-dev` `phpunit/phpunit ^11.5` (used from Task 4 on).

- [ ] **Step 1: Write the failing check (consumer install)**

This check installs the working tree into a throwaway project, the way a consuming project would. It isn't committed. Run it from the repository root:

```bash
consumer="$(mktemp -d)"
cat > "$consumer/composer.json" <<'EOF'
{
    "repositories": [{"type": "path", "url": "/pkg", "options": {"symlink": false, "versions": {"heimrichhannot/deployer-recipes": "2.0.0"}}}],
    "require-dev": {"heimrichhannot/deployer-recipes": "2.0.0"},
    "config": {"platform": {"php": "8.3.0"}}
}
EOF
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer -v "$PWD":/pkg -v "$consumer":/app -w /app composer:2 composer install --no-interaction --quiet
docker run --rm -u "$(id -u):$(id -g)" -v "$consumer":/app -w /app php:8.3-cli php vendor/bin/dep --version
```

Expected before the change: the install pulls `deployer/deployer` in as a dependency (that's what we are removing), and the version check either fails or prints the composer-installed Deployer rather than the bundled phar. Keep `$consumer` for Step 6.

- [ ] **Step 2: Download the phar**

```bash
mkdir -p bin
.github/scripts/fetch-phar.sh 8.0.5 bin
```

Expected: `ok: /tmp/…/deployer.phar is signed by the Deployer sign key`, plus `bin/deployer.phar` (about 595 KB) and `bin/deployer.phar.asc`.

- [ ] **Step 3: Write the wrapper**

Create `bin/dep`, then `chmod +x bin/dep`:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/deployer.phar';
```

Verify: `php83 bin/dep --version` → `Deployer 8.0.5`.

- [ ] **Step 4: Replace `composer.json`**

Write the whole file. `description`, `homepage`, `license` and `authors` stay as they are; `deployer/deployer` leaves `require`.

```json
{
    "name": "heimrichhannot/deployer-recipes",
    "description": "A Heimrich & Hannot Contao project",
    "type": "library",
    "homepage": "https://github.com/heimrichhannot/deployer-recipes",
    "license": "proprietary",
    "authors": [
        {
            "name": "Heimrich & Hannot GmbH",
            "email": "digitales@heimrich-hannot.de",
            "homepage": "https://www.heimrich-hannot.de"
        }
    ],
    "require": {
        "php": "^8.3",
        "ext-json": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5"
    },
    "conflict": {
        "deployer/deployer": "*"
    },
    "autoload": {
        "files": [
            "autoload.php"
        ]
    },
    "bin": [
        "bin/dep"
    ],
    "config": {
        "platform": {
            "php": "8.3.0"
        }
    },
    "extra": {
        "branch-alias": {
            "dev-main": "2.x-dev"
        },
        "deployer": {
            "version": "8.0.5"
        }
    }
}
```

Verify: `in-composer composer validate --strict --no-check-lock` → `./composer.json is valid`.

- [ ] **Step 5: Packaging files**

Create `.gitattributes`:

```
bin/deployer.phar binary
bin/deployer.phar.asc export-ignore
/.github export-ignore
/docs export-ignore
/tests export-ignore
/phpunit.xml.dist export-ignore
/.gitattributes export-ignore
```

Append to `.gitignore`:

```
/vendor/
/composer.lock
/.phpunit.cache/
```

Verify: `git check-attr -a bin/deployer.phar tests/smoke` lists `binary: set` for the phar and `export-ignore: set` for `tests/smoke`.

- [ ] **Step 6: Re-run the consumer check**

Run the two `docker run` lines from Step 1 again, with the same `$consumer` (delete `$consumer/vendor` and `$consumer/composer.lock` first).
Expected: the install succeeds **without** `deployer/deployer` in `$consumer/vendor`, and the version check prints `Deployer 8.0.5`. Also run `ls "$consumer/vendor/deployer" 2>&1`, which should report no such directory.

Then check the conflict:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer -v "$PWD":/pkg -v "$consumer":/app -w /app composer:2 composer require --dev deployer/deployer:^8.0 --dry-run --no-interaction
```

Expected: exits non-zero, with output mentioning that `heimrichhannot/deployer-recipes` conflicts with `deployer/deployer`. Afterwards: `rm -rf "$consumer"`.

- [ ] **Step 7: Commit**

```bash
git add bin/dep bin/deployer.phar bin/deployer.phar.asc composer.json .gitattributes .gitignore
git commit -m "Bundle the Deployer 8.0.5 phar as vendor/bin/dep" -m "deployer/deployer is no longer a dependency: its Symfony 7.4+ requirement blocked installation in Contao 4.13 and 5.3 projects. The phar bundles its own dependencies." -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

Verify: `git ls-files -s bin/dep` shows mode `100755`.

---

### Task 3: Smoke test

**Files:**
- Create: `tests/smoke/run.sh`
- Create: `tests/smoke/contao/deploy.php`, `tests/smoke/symfony/deploy.php`, `tests/smoke/typo3/deploy.php`

**Interfaces:**
- Consumes: `bin/dep` and `composer.json` `extra.deployer.version` (Task 2).
- Produces: `tests/smoke/run.sh`, which exits 0 only if `php bin/dep --version` prints `Deployer <extra.deployer.version>` and every fixture's `list` and `tree deploy` succeed, with our `ask_production_confirmation` hook present. It needs `php` on PATH and no network.

- [ ] **Step 1: Write the failing test**

Create `tests/smoke/run.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Smoke test: the bundled phar runs and every recipe loads with it. No network access needed.
set -euo pipefail
cd "$(dirname "$0")/../.."

expected="Deployer $(php -r 'echo json_decode(file_get_contents("composer.json"), true)["extra"]["deployer"]["version"];')"
actual="$(php bin/dep --version)"
if [ "$actual" != "$expected" ]; then
    echo "FAIL: expected '$expected', got '$actual'" >&2
    exit 1
fi
echo "ok: $actual"

for recipe in contao symfony typo3; do
    deploy_file="tests/smoke/$recipe/deploy.php"
    php bin/dep --file "$deploy_file" list > /dev/null
    tree="$(php bin/dep --file "$deploy_file" tree deploy)"
    if ! grep -q ask_production_confirmation <<<"$tree"; then
        echo "FAIL: $recipe: our hooks are missing from the deploy task tree" >&2
        exit 1
    fi
    echo "ok: $recipe"
done
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `in-php83 tests/smoke/run.sh`
Expected: `ok: Deployer 8.0.5`, then a Deployer error for the contao fixture, and a non-zero exit. Deployer silently ignores a `--file` that doesn't exist, so `list` still succeeds; the error comes from `tree deploy`, because no `deploy` task is defined. The `grep` for our hook is what catches a fixture that doesn't load.

- [ ] **Step 3: Write the fixtures**

`tests/smoke/contao/deploy.php`:

```php
<?php

namespace Deployer;

import(__DIR__ . '/../../../autoload.php');
recipe('contao');

host('smoke.example.org')
    ->setRemoteUser('smoke')
    ->set('deploy_path', '/var/www/smoke')
    ->set('public_url', 'https://smoke.example.org');
```

`tests/smoke/symfony/deploy.php`:

```php
<?php

namespace Deployer;

import(__DIR__ . '/../../../autoload.php');
recipe('symfony');

host('smoke.example.org')
    ->setRemoteUser('smoke')
    ->set('deploy_path', '/var/www/smoke')
    ->set('public_url', 'https://smoke.example.org');
```

`tests/smoke/typo3/deploy.php`:

```php
<?php

namespace Deployer;

import(__DIR__ . '/../../../autoload.php');
recipe('typo3');

host('smoke.example.org')
    ->setRemoteUser('smoke')
    ->set('deploy_path', '/var/www/smoke')
    ->set('public_url', 'https://smoke.example.org');
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `in-php83 tests/smoke/run.sh`
Expected: `ok: Deployer 8.0.5`, `ok: contao`, `ok: symfony`, `ok: typo3`, exit 0.

- [ ] **Step 5: Check that the test can fail**

Temporarily break a recipe import, run the test, and restore:

```bash
sed -i "s#import('recipe-huh/contao/database.php');#import('recipe-huh/contao/missing.php');#" recipe-huh/contao.php
in-php83 tests/smoke/run.sh; echo "exit=$?"
git checkout recipe-huh/contao.php
```

Expected: a Deployer error about `missing.php`, and `exit=1`.

- [ ] **Step 6: Commit**

```bash
git add tests/smoke
git commit -m "Add smoke test that loads every recipe with the bundled phar" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Runtime Deployer version guard

**Files:**
- Create: `extension/VersionGuard.php`
- Create: `phpunit.xml.dist`, `tests/bootstrap.php`
- Test: `tests/unit/VersionGuardTest.php`
- Modify: `autoload.php` (between the `DEPLOYER` check and the `require_once` lines)

**Interfaces:**
- Consumes: `composer.json` `extra.deployer.version` (Task 2); the `DEPLOYER_VERSION` constant, which Deployer's `Deployer::run()` defines before it imports `deploy.php`.
- Produces:
  - `\HeimrichHannot\DeployerRecipes\VersionGuard::assertCompatible(string $required, string $running): void`. It throws `\RuntimeException` if `$required` isn't a version, or if `$running` has another major.
  - The PHPUnit setup (`phpunit.xml.dist`, `tests/bootstrap.php`) that Task 5 extends.

- [ ] **Step 1: Install dev dependencies**

Run: `in-composer composer install --no-interaction`
Expected: installs `phpunit/phpunit` 11.5.x into `vendor/`, which is gitignored.

- [ ] **Step 2: Write the PHPUnit setup and the failing test**

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

`tests/bootstrap.php`:

```php
<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../extension/VersionGuard.php';
```

`tests/unit/VersionGuardTest.php`:

```php
<?php

namespace HeimrichHannot\DeployerRecipes\Tests;

use HeimrichHannot\DeployerRecipes\VersionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionGuardTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function compatible(): iterable
    {
        yield 'same version' => ['8.0.5', '8.0.5'];
        yield 'newer minor at runtime' => ['8.0.5', '8.1.0'];
        yield 'older patch at runtime' => ['8.0.5', '8.0.1'];
        yield 'v prefix' => ['8.0.5', 'v8.0.5'];
    }

    #[DataProvider('compatible')]
    public function testAcceptsSameMajor(string $required, string $running): void
    {
        $this->expectNotToPerformAssertions();
        VersionGuard::assertCompatible($required, $running);
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompatible(): iterable
    {
        yield 'older major' => ['8.0.5', '7.4.0'];
        yield 'newer major' => ['8.0.5', '9.0.0'];
        yield 'unknown running version' => ['8.0.5', 'unknown'];
        yield 'major that only shares a prefix' => ['8.0.5', '80.0.0'];
    }

    #[DataProvider('incompatible')]
    public function testRejectsOtherMajor(string $required, string $running): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("requires Deployer 8, but was loaded by Deployer $running. Run `vendor/bin/dep`.");
        VersionGuard::assertCompatible($required, $running);
    }

    public function testRejectsInvalidRequiredVersion(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid extra.deployer.version ""');
        VersionGuard::assertCompatible('', '8.0.5');
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php83 vendor/bin/phpunit`
Expected: a fatal error, because `extension/VersionGuard.php` can't be opened in `tests/bootstrap.php`.

- [ ] **Step 4: Write the guard**

`extension/VersionGuard.php`:

```php
<?php

namespace HeimrichHannot\DeployerRecipes;

/**
 * Stops the recipes from running under a Deployer major version they were not written for.
 */
final class VersionGuard
{
    /**
     * @param string $required the bundled Deployer version from composer.json extra.deployer.version
     * @param string $running  the running Deployer version (DEPLOYER_VERSION)
     *
     * @throws \RuntimeException if $required is not a version or $running has a different major version
     */
    public static function assertCompatible(string $required, string $running): void
    {
        $requiredMajor = self::major($required);
        if ($requiredMajor === null) {
            throw new \RuntimeException(\sprintf('heimrichhannot/deployer-recipes: invalid extra.deployer.version "%s" in composer.json.', $required));
        }

        if (self::major($running) !== $requiredMajor) {
            throw new \RuntimeException(\sprintf('heimrichhannot/deployer-recipes requires Deployer %d, but was loaded by Deployer %s. Run `vendor/bin/dep`.', $requiredMajor, $running));
        }
    }

    private static function major(string $version): ?int
    {
        return \preg_match('/^v?(\d+)\.\d+/', $version, $match) ? (int) $match[1] : null;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php83 vendor/bin/phpunit`
Expected: `OK (9 tests, 10 assertions)`.

- [ ] **Step 6: Write the failing end-to-end check**

With a patched required version, loading the recipes must fail:

```bash
sed -i 's/"version": "8.0.5"/"version": "7.4.0"/' composer.json
php83 bin/dep --file tests/smoke/contao/deploy.php list; echo "exit=$?"
git checkout composer.json
```

Expected **before** wiring the guard in: the task list is printed and `exit=0`. That is the failure we're fixing.

- [ ] **Step 7: Wire the guard into `autoload.php`**

The new `autoload.php`. Only the guard block is new; the comment and the brace style stay unchanged:

```php
<?php

namespace Deployer;

\set_include_path(\get_include_path() . PATH_SEPARATOR . __DIR__);

// DEPLOYER is defined by bin/dep after the composer autoloader ran, so this file is
// a no-op when loaded via composer's "files" autoload (e.g. by the application itself)
if (!\defined('DEPLOYER'))
{
    return;
}

// Fail early with a clear message instead of obscure errors when a different Deployer
// major version (e.g. a globally installed dep) loads these recipes
require_once 'extension/VersionGuard.php';

$composer = \json_decode((string) \file_get_contents(__DIR__ . '/composer.json'), true);
\HeimrichHannot\DeployerRecipes\VersionGuard::assertCompatible(
    (string) ($composer['extra']['deployer']['version'] ?? ''),
    \defined('DEPLOYER_VERSION') ? \DEPLOYER_VERSION : 'unknown',
);

require_once 'extension/functions.php';
require_once 'extension/tasks.php';
```

- [ ] **Step 8: Run the end-to-end check to verify it passes**

Run the three commands from Step 6 again.
Expected: `heimrichhannot/deployer-recipes requires Deployer 7, but was loaded by Deployer 8.0.5. Run `vendor/bin/dep`.` and `exit=1`. Afterwards, `git diff composer.json` must be empty.

Then run: `in-php83 tests/smoke/run.sh` → all four `ok:` lines. The guard passes for the matching version.

- [ ] **Step 9: Commit**

```bash
git add extension/VersionGuard.php autoload.php phpunit.xml.dist tests/bootstrap.php tests/unit/VersionGuardTest.php
git commit -m "Refuse to run under a Deployer major other than the bundled one" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Update decision logic

**Files:**
- Create: `.github/scripts/DeployerUpdate.php`
- Test: `tests/unit/DeployerUpdateTest.php`
- Modify: `tests/bootstrap.php` (add one `require`)

**Interfaces:**
- Consumes: the PHPUnit setup (Task 4).
- Produces: `final class \HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate`, with static methods and no I/O:
  - `stableVersions(list<string> $tags): list<string>`: strict `X.Y.Z` values, `v` stripped, unique, ascending.
  - `major(string $version): int`: accepts `8.0.5`, `v8.0.5`, `2.x-dev`; throws `\InvalidArgumentException` otherwise.
  - `latestInMajor(list<string> $versions, int $major): ?string`
  - `latestAboveMajor(list<string> $versions, int $major): ?string`
  - `nextRecipesVersion(string $latestRecipesTag, string $currentDeployer, string $newDeployer): string`: a Deployer patch bumps the recipes patch, a Deployer minor bumps the recipes minor. Throws `\InvalidArgumentException` on a major change or when the new version isn't newer.
  - `unreleasedIsEmpty(string $changelog): bool`
  - `bundleLine(string $deployerVersion): string`: `- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).`
  - `insertRelease(string $changelog, string $version, string $date, string $deployerVersion): string`: throws `\LogicException` if `[Unreleased]` isn't empty.
  - `addUnreleasedEntry(string $changelog, string $deployerVersion): string`
  - `plan(string $currentDeployer, int $recipesMajor, list<string> $upstreamTags, list<string> $recipesTags, string $changelog): array{mode, current, same_major, next_major, recipes_version, next_recipes_major}` (all string values).
    - `mode` is one of `none`, `untagged`, `pr` or `release`.
    - `same_major`, `next_major` and `recipes_version` are `''` when not applicable.
  - Every changelog method throws `\InvalidArgumentException` when there is no `## [Unreleased]` heading.

- [ ] **Step 1: Write the failing test**

Add to `tests/bootstrap.php`:

```php
require __DIR__ . '/../.github/scripts/DeployerUpdate.php';
```

Create `tests/unit/DeployerUpdateTest.php`:

```php
<?php

namespace HeimrichHannot\DeployerRecipes\Tests;

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployerUpdateTest extends TestCase
{
    private const CHANGELOG_HEAD = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\n";

    public function testStableVersionsKeepsOnlyStrictVersionsSortedAscending(): void
    {
        self::assertSame(
            ['7.5.12', '8.0.4', '8.0.10', '9.0.0'],
            DeployerUpdate::stableVersions(['v8.0.10', 'v9.0.0', 'v8.1.0-rc.1', '8.0.4', 'v7.5.12', 'latest', 'v8.0.10', " v8.0.4\n"]),
        );
    }

    public function testLatestInMajor(): void
    {
        $versions = ['7.5.12', '8.0.4', '8.0.10', '9.0.0'];

        self::assertSame('8.0.10', DeployerUpdate::latestInMajor($versions, 8));
        self::assertNull(DeployerUpdate::latestInMajor($versions, 10));
    }

    public function testLatestAboveMajor(): void
    {
        self::assertSame('10.1.0', DeployerUpdate::latestAboveMajor(['8.0.5', '9.0.0', '10.1.0'], 8));
        self::assertNull(DeployerUpdate::latestAboveMajor(['7.5.12', '8.0.5'], 8));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function bumps(): iterable
    {
        yield 'Deployer patch → recipes patch' => ['2.3.1', '8.0.5', '8.0.6', '2.3.2'];
        yield 'skipped Deployer patches → one recipes patch' => ['2.3.1', '8.0.5', '8.0.9', '2.3.2'];
        yield 'Deployer minor → recipes minor, patch reset' => ['2.3.1', '8.0.5', '8.1.0', '2.4.0'];
        yield 'Deployer minor and patch → recipes minor' => ['2.3.1', '8.0.5', '8.2.3', '2.4.0'];
        yield 'two-digit patch' => ['2.0.9', '8.0.9', '8.0.10', '2.0.10'];
    }

    #[DataProvider('bumps')]
    public function testNextRecipesVersion(string $latestTag, string $current, string $new, string $expected): void
    {
        self::assertSame($expected, DeployerUpdate::nextRecipesVersion($latestTag, $current, $new));
    }

    public function testNextRecipesVersionRejectsMajorChange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeployerUpdate::nextRecipesVersion('2.3.1', '8.0.5', '9.0.0');
    }

    public function testNextRecipesVersionRejectsDowngrade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeployerUpdate::nextRecipesVersion('2.3.1', '8.0.5', '8.0.5');
    }

    public function testUnreleasedIsEmpty(): void
    {
        self::assertTrue(DeployerUpdate::unreleasedIsEmpty(self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.0.0] - 2026-10-01\n\n- x\n"));
        self::assertTrue(DeployerUpdate::unreleasedIsEmpty(self::CHANGELOG_HEAD . "## [Unreleased]\n"));
        self::assertFalse(DeployerUpdate::unreleasedIsEmpty(self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n\n## [2.0.0] - 2026-10-01\n"));
    }

    public function testChangelogWithoutUnreleasedHeadingIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeployerUpdate::unreleasedIsEmpty("# Changelog\n\n## [2.0.0] - 2026-10-01\n");
    }

    public function testInsertReleaseBelowEmptyUnreleased(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.3.1] - 2026-10-01\n\n### Fixed\n\n- y\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n"
            . "## [2.3.2] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n\n"
            . "## [2.3.1] - 2026-10-01\n\n### Fixed\n\n- y\n",
            DeployerUpdate::insertRelease($changelog, '2.3.2', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseWhenUnreleasedIsTheLastSection(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.0.1] - 2026-10-05\n\n### Changed\n\n"
            . "- Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).\n",
            DeployerUpdate::insertRelease(self::CHANGELOG_HEAD . "## [Unreleased]\n", '2.0.1', '2026-10-05', '8.0.6'),
        );
    }

    public function testInsertReleaseRefusesNonEmptyUnreleased(): void
    {
        $this->expectException(\LogicException::class);
        DeployerUpdate::insertRelease(self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n", '2.3.2', '2026-10-05', '8.0.6');
    }

    public function testAddUnreleasedEntryPrependsToExistingChangedList(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n- Something\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n"
            . "- Something\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n",
            DeployerUpdate::addUnreleasedEntry($changelog, '9.0.0'),
        );
    }

    public function testAddUnreleasedEntryCreatesChangedList(): void
    {
        $changelog = self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n";

        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n\n"
            . "### Fixed\n\n- y\n\n## [2.3.1] - 2026-10-01\n\n- z\n",
            DeployerUpdate::addUnreleasedEntry($changelog, '9.0.0'),
        );
    }

    public function testAddUnreleasedEntryToEmptyUnreleased(): void
    {
        self::assertSame(
            self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n"
            . "- Bundle Deployer 9.0.0 ([release notes](https://github.com/deployphp/deployer/releases/tag/v9.0.0)).\n\n"
            . "## [2.3.1] - 2026-10-01\n",
            DeployerUpdate::addUnreleasedEntry(self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.3.1] - 2026-10-01\n", '9.0.0'),
        );
    }

    public function testPlanReleasesSameMajorUpdate(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6', 'v8.0.5', 'v7.5.12'], ['1.15.0', '2.0.0'], self::CHANGELOG_HEAD . "## [Unreleased]\n\n## [2.0.0] - 2026-10-01\n");

        self::assertSame(
            ['mode' => 'release', 'current' => '8.0.5', 'same_major' => '8.0.6', 'next_major' => '', 'recipes_version' => '2.0.1', 'next_recipes_major' => '3'],
            $plan,
        );
    }

    public function testPlanIgnoresOlderAndEqualReleases(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.5', 'v8.0.4'], ['2.0.0'], self::CHANGELOG_HEAD . "## [Unreleased]\n");

        self::assertSame('none', $plan['mode']);
        self::assertSame('', $plan['same_major']);
    }

    public function testPlanOpensPullRequestWhenUnreleasedHasContent(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6'], ['2.0.0'], self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Fixed\n\n- y\n");

        self::assertSame('pr', $plan['mode']);
        self::assertSame('8.0.6', $plan['same_major']);
        self::assertSame('', $plan['recipes_version']);
    }

    public function testPlanWaitsForFirstTagInRecipesMajor(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v8.0.6'], ['1.15.0'], self::CHANGELOG_HEAD . "## [Unreleased]\n\n### Changed\n\n- v2 work\n");

        self::assertSame('untagged', $plan['mode']);
    }

    public function testPlanReportsNextMajorAlongsideSameMajorUpdate(): void
    {
        $plan = DeployerUpdate::plan('8.0.5', 2, ['v9.0.1', 'v9.0.0', 'v8.0.6'], ['2.0.0'], self::CHANGELOG_HEAD . "## [Unreleased]\n");

        self::assertSame('release', $plan['mode']);
        self::assertSame('8.0.6', $plan['same_major']);
        self::assertSame('9.0.1', $plan['next_major']);
        self::assertSame('3', $plan['next_recipes_major']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php83 vendor/bin/phpunit`
Expected: a fatal error, because `.github/scripts/DeployerUpdate.php` can't be opened in `tests/bootstrap.php`.

- [ ] **Step 3: Write the implementation**

`.github/scripts/DeployerUpdate.php`:

```php
<?php

namespace HeimrichHannot\DeployerRecipes\Ci;

/**
 * Pure decision logic for the Deployer update workflow. No I/O; see deployer-update.php for the CLI.
 */
final class DeployerUpdate
{
    private const UNRELEASED = '## [Unreleased]';

    /**
     * @param list<string> $tags e.g. ["v8.0.5", "v8.1.0-rc.1", "8.0.4"]
     *
     * @return list<string> strict X.Y.Z versions without "v" prefix, ascending
     */
    public static function stableVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            if (\preg_match('/^v?(\d+\.\d+\.\d+)$/', \trim($tag), $match)) {
                $versions[$match[1]] = true;
            }
        }
        $versions = \array_keys($versions);
        \usort($versions, 'version_compare');

        return $versions;
    }

    public static function major(string $version): int
    {
        if (!\preg_match('/^v?(\d+)\./', $version, $match)) {
            throw new \InvalidArgumentException(\sprintf('Not a version: "%s"', $version));
        }

        return (int) $match[1];
    }

    /** @param list<string> $versions ascending, as returned by stableVersions() */
    public static function latestInMajor(array $versions, int $major): ?string
    {
        $inMajor = \array_filter($versions, static fn (string $v): bool => self::major($v) === $major);

        return $inMajor === [] ? null : \end($inMajor);
    }

    /** @param list<string> $versions ascending, as returned by stableVersions() */
    public static function latestAboveMajor(array $versions, int $major): ?string
    {
        $above = \array_filter($versions, static fn (string $v): bool => self::major($v) > $major);

        return $above === [] ? null : \end($above);
    }

    /**
     * Deployer patch release → recipes patch bump; Deployer minor release → recipes minor bump.
     */
    public static function nextRecipesVersion(string $latestRecipesTag, string $currentDeployer, string $newDeployer): string
    {
        if (self::major($currentDeployer) !== self::major($newDeployer)) {
            throw new \InvalidArgumentException('Deployer major changes need a pull request, not an automatic release.');
        }
        if (!\version_compare($newDeployer, $currentDeployer, '>')) {
            throw new \InvalidArgumentException(\sprintf('Deployer %s is not newer than %s.', $newDeployer, $currentDeployer));
        }

        [$major, $minor, $patch] = \array_map('intval', \explode('.', self::stableVersions([$latestRecipesTag])[0] ?? throw new \InvalidArgumentException(\sprintf('Not a version: "%s"', $latestRecipesTag))));
        $minorChanged = \explode('.', $currentDeployer)[1] !== \explode('.', $newDeployer)[1];

        return $minorChanged ? "$major." . ($minor + 1) . '.0' : "$major.$minor." . ($patch + 1);
    }

    public static function unreleasedIsEmpty(string $changelog): bool
    {
        [, $body] = self::splitUnreleased($changelog);

        return \trim($body) === '';
    }

    public static function bundleLine(string $deployerVersion): string
    {
        return \sprintf('- Bundle Deployer %1$s ([release notes](https://github.com/deployphp/deployer/releases/tag/v%1$s)).', $deployerVersion);
    }

    /** Adds a release section below an empty [Unreleased] section. */
    public static function insertRelease(string $changelog, string $version, string $date, string $deployerVersion): string
    {
        [$before, $body, $after] = self::splitUnreleased($changelog);
        if (\trim($body) !== '') {
            throw new \LogicException('[Unreleased] is not empty; release it by hand.');
        }

        $section = "## [$version] - $date\n\n### Changed\n\n" . self::bundleLine($deployerVersion) . "\n";

        return $before . "\n\n" . $section . ($after === '' ? '' : "\n" . $after);
    }

    /** Adds the bundle line to the "### Changed" list of [Unreleased], creating that list if needed. */
    public static function addUnreleasedEntry(string $changelog, string $deployerVersion): string
    {
        [$before, $body, $after] = self::splitUnreleased($changelog);
        $line = self::bundleLine($deployerVersion);

        if (\preg_match('/^### Changed\n\n/m', $body)) {
            $body = \preg_replace('/^### Changed\n\n/m', "### Changed\n\n$line\n", $body, 1);
        } else {
            $body = "\n\n### Changed\n\n$line\n" . (\trim($body) === '' ? '' : "\n" . \ltrim($body, "\n"));
        }

        $body = \rtrim($body, "\n") . "\n";

        return $before . $body . ($after === '' ? '' : "\n" . $after);
    }

    /**
     * @param list<string> $upstreamTags deployphp/deployer release tags
     * @param list<string> $recipesTags  tags of this repository
     *
     * @return array{mode: string, current: string, same_major: string, next_major: string, recipes_version: string, next_recipes_major: string}
     *   mode: "none" (no same-major update), "untagged" (no release in this recipes major yet),
     *   "pr" ([Unreleased] not empty) or "release"
     */
    public static function plan(string $currentDeployer, int $recipesMajor, array $upstreamTags, array $recipesTags, string $changelog): array
    {
        $upstream = self::stableVersions($upstreamTags);
        $deployerMajor = self::major($currentDeployer);

        $sameMajor = self::latestInMajor($upstream, $deployerMajor);
        if ($sameMajor !== null && !\version_compare($sameMajor, $currentDeployer, '>')) {
            $sameMajor = null;
        }
        $latestRecipesTag = self::latestInMajor(self::stableVersions($recipesTags), $recipesMajor);

        $mode = match (true) {
            $sameMajor === null => 'none',
            $latestRecipesTag === null => 'untagged',
            !self::unreleasedIsEmpty($changelog) => 'pr',
            default => 'release',
        };

        return [
            'mode' => $mode,
            'current' => $currentDeployer,
            'same_major' => $sameMajor ?? '',
            'next_major' => self::latestAboveMajor($upstream, $deployerMajor) ?? '',
            'recipes_version' => $mode === 'release' ? self::nextRecipesVersion($latestRecipesTag, $currentDeployer, $sameMajor) : '',
            'next_recipes_major' => (string) ($recipesMajor + 1),
        ];
    }

    /** @return array{string, string, string} text up to and including the heading, the section body, the rest starting at the next "## " */
    private static function splitUnreleased(string $changelog): array
    {
        $start = \strpos($changelog, self::UNRELEASED);
        if ($start === false) {
            throw new \InvalidArgumentException('CHANGELOG has no "## [Unreleased]" heading.');
        }
        $headingEnd = $start + \strlen(self::UNRELEASED);
        $next = \strpos($changelog, "\n## ", $headingEnd);
        $bodyEnd = $next === false ? \strlen($changelog) : $next + 1;

        return [\substr($changelog, 0, $headingEnd), \substr($changelog, $headingEnd, $bodyEnd - $headingEnd), \substr($changelog, $bodyEnd)];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php83 vendor/bin/phpunit`
Expected: `OK (32 tests, 43 assertions)`.

- [ ] **Step 5: Check against the real CHANGELOG**

Run: `php83 -r 'require ".github/scripts/DeployerUpdate.php"; var_dump(HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate::unreleasedIsEmpty(file_get_contents("CHANGELOG.md")));'`
Expected: `bool(false)`, because the current `[Unreleased]` holds the v2 changes.

- [ ] **Step 6: Commit**

```bash
git add .github/scripts/DeployerUpdate.php tests/unit/DeployerUpdateTest.php tests/bootstrap.php
git commit -m "Add decision logic for automated Deployer updates" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Workflow CLI and idempotent pull requests

**Files:**
- Create: `.github/scripts/deployer-update.php`
- Create: `.github/scripts/open-pr.sh`
- Test: `tests/scripts/deployer-update-cli-test.sh`
- Test: `tests/scripts/open-pr-test.sh`

**Interfaces:**
- Consumes: `DeployerUpdate` (Task 5); `composer.json` `extra.deployer.version` and `extra.branch-alias.dev-main` (Task 2).
- Produces (all run from the repository root):
  - `php .github/scripts/deployer-update.php plan < upstream-tags`: prints `mode=…`, `current=…`, `same_major=…`, `next_major=…`, `recipes_version=…` and `next_recipes_major=…` lines. It exits non-zero if `git tag --list` fails or `composer.json` lacks the keys.
  - `php .github/scripts/deployer-update.php release-changelog <recipes-version> <deployer-version>`: edits `CHANGELOG.md`, dated with UTC today.
  - `php .github/scripts/deployer-update.php unreleased-changelog <deployer-version>`: edits `CHANGELOG.md`.
  - Unknown command: exit 2.
  - `.github/scripts/open-pr.sh <branch> <deployer-version> <true|false> <body-file>`: commits the working tree to `<branch>`, force-pushes it and creates or edits the PR against `main` via `gh`. It does nothing if `origin/<branch>` already bundles `<deployer-version>`.

- [ ] **Step 1: Write the failing CLI test**

Create `tests/scripts/deployer-update-cli-test.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Tests the .github/scripts/deployer-update.php CLI in a throwaway git repository.
set -euo pipefail

cli="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/deployer-update.php"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet "$work/repo"
cd "$work/repo"
echo '{"extra": {"branch-alias": {"dev-main": "2.x-dev"}, "deployer": {"version": "8.0.5"}}}' > composer.json
printf '# Changelog\n\n## [Unreleased]\n\n## [2.0.0] - 2026-10-01\n\n- x\n' > CHANGELOG.md
git add . && git commit --quiet -m init && git tag 1.15.0 && git tag 2.0.0

plan="$(printf 'v9.0.0\nv8.0.6\nv8.1.0-rc.1\nv8.0.5\n' | php "$cli" plan)"
check 'plan releases 2.0.1' 'grep -qx "mode=release" <<<"$plan" && grep -qx "recipes_version=2.0.1" <<<"$plan"'
check 'plan picks same-major 8.0.6' 'grep -qx "same_major=8.0.6" <<<"$plan"'
check 'plan reports next major 9.0.0' 'grep -qx "next_major=9.0.0" <<<"$plan" && grep -qx "next_recipes_major=3" <<<"$plan"'

php "$cli" release-changelog 2.0.1 8.0.6
check 'release-changelog adds a dated section' 'grep -qx "## \[2.0.1\] - $(date -u +%F)" CHANGELOG.md'

git checkout --quiet CHANGELOG.md
php "$cli" unreleased-changelog 9.0.0
check 'unreleased-changelog adds the entry' 'grep -q "^- Bundle Deployer 9.0.0 " CHANGELOG.md'

check 'unknown command exits 2' '[ "$(php "$cli" bogus 2>/dev/null; echo $?)" = 2 ]'

rm -rf .git
check 'plan fails without git tags instead of reporting "untagged"' '! printf "v8.0.6\n" | php "$cli" plan > /dev/null 2>&1'

exit $fails
```

- [ ] **Step 2: Run it to verify it fails**

Run: `in-composer bash tests/scripts/deployer-update-cli-test.sh`
Expected: `Could not open input file: …/deployer-update.php`, and the script aborts at the first `plan` call with a non-zero exit.

- [ ] **Step 3: Write the CLI**

`.github/scripts/deployer-update.php`:

```php
<?php

/*
 * CLI for the Deployer update workflow. Run from the repository root.
 *
 *   gh release list ... | php .github/scripts/deployer-update.php plan
 *       Prints key=value lines (for $GITHUB_OUTPUT), see DeployerUpdate::plan().
 *   php .github/scripts/deployer-update.php release-changelog <recipes-version> <deployer-version>
 *       Adds a release section below the empty [Unreleased] section of CHANGELOG.md.
 *   php .github/scripts/deployer-update.php unreleased-changelog <deployer-version>
 *       Adds a "Bundle Deployer" entry to the [Unreleased] section of CHANGELOG.md.
 */

use HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate;

require __DIR__ . '/DeployerUpdate.php';

$composer = \json_decode(\file_get_contents('composer.json'), true, flags: \JSON_THROW_ON_ERROR);
$changelog = \file_get_contents('CHANGELOG.md');

switch ($argv[1] ?? '') {
    case 'plan':
        \exec('git tag --list', $recipesTags, $exitCode);
        if ($exitCode !== 0) {
            throw new \RuntimeException('"git tag --list" failed; run from a git checkout with tags fetched.');
        }
        $branchAlias = $composer['extra']['branch-alias']['dev-main'] ?? throw new \RuntimeException('composer.json has no extra.branch-alias.dev-main');
        $plan = DeployerUpdate::plan(
            $composer['extra']['deployer']['version'] ?? throw new \RuntimeException('composer.json has no extra.deployer.version'),
            DeployerUpdate::major($branchAlias),
            \preg_split('/\R/', \stream_get_contents(\STDIN), -1, \PREG_SPLIT_NO_EMPTY),
            $recipesTags,
            $changelog,
        );
        foreach ($plan as $key => $value) {
            echo "$key=$value\n";
        }
        break;

    case 'release-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::insertRelease($changelog, $argv[2], \gmdate('Y-m-d'), $argv[3]));
        break;

    case 'unreleased-changelog':
        \file_put_contents('CHANGELOG.md', DeployerUpdate::addUnreleasedEntry($changelog, $argv[2]));
        break;

    default:
        \fwrite(\STDERR, "Usage: deployer-update.php plan|release-changelog <recipes-version> <deployer-version>|unreleased-changelog <deployer-version>\n");
        exit(2);
}
```

- [ ] **Step 4: Run the CLI test to verify it passes**

Run: `in-composer bash tests/scripts/deployer-update-cli-test.sh`
Expected: seven `ok:` lines, exit 0.

- [ ] **Step 5: Write the failing PR test**

Create `tests/scripts/open-pr-test.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Tests .github/scripts/open-pr.sh against a local bare remote and a stub `gh` that logs its arguments.
set -euo pipefail

script="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/open-pr.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

# Stub gh: "pr list" prints $work/open-pr (empty = no open PR); every call is logged.
mkdir "$work/stub"
cat > "$work/stub/gh" <<STUB
#!/usr/bin/env bash
echo "\$*" >> "$work/gh.log"
if [ "\$1 \$2" = "pr list" ]; then cat "$work/open-pr" 2>/dev/null || true; fi
STUB
chmod +x "$work/stub/gh"
export PATH="$work/stub:$PATH"
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet --bare "$work/remote.git"
git init --quiet -b main "$work/repo"
cd "$work/repo"
git remote add origin "$work/remote.git"
echo '{"extra": {"deployer": {"version": "8.0.5"}}}' > composer.json
git add composer.json && git commit --quiet -m init && git push --quiet origin main
echo body > "$work/body.md"
bump() { echo "{\"extra\": {\"deployer\": {\"version\": \"$1\"}}}" > composer.json; }
remote_version() { git --git-dir="$work/remote.git" show "deployer/9:composer.json" | grep -o '"version": "[^"]*"'; }

# 1. No branch, no PR: pushes the branch and creates a draft PR.
bump 9.0.0
"$script" deployer/9 9.0.0 true "$work/body.md" > /dev/null
check 'first run pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.0\"" ]'
check 'first run creates a draft PR' 'grep -q -- "pr create --base main --head deployer/9 --title Bundle Deployer 9.0.0 --body-file $work/body.md --draft" "$work/gh.log"'

# 2. Same version again: no push, no gh calls.
git switch --quiet main && bump 9.0.0 && : > "$work/gh.log"
before="$(git --git-dir="$work/remote.git" rev-parse deployer/9)"
"$script" deployer/9 9.0.0 true "$work/body.md" > /dev/null
check 'unchanged version does not push' '[ "$(git --git-dir="$work/remote.git" rev-parse deployer/9)" = "$before" ]'
check 'unchanged version does not call gh' '[ ! -s "$work/gh.log" ]'

# 3. Newer version with the PR still open: force-pushes and edits the PR instead of creating another.
git checkout --quiet -f main && bump 9.0.1 && echo 42 > "$work/open-pr"
"$script" deployer/9 9.0.1 true "$work/body.md" > /dev/null
check 'newer version force-pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.1\"" ]'
check 'newer version edits the open PR' 'grep -q -- "pr edit 42 --title Bundle Deployer 9.0.1" "$work/gh.log"'
check 'newer version creates no second PR' '[ "$(grep -c "pr create" "$work/gh.log")" = 0 ]'

# 4. Non-draft mode omits --draft.
git checkout --quiet -f main && bump 8.0.6 && rm -f "$work/open-pr" && : > "$work/gh.log"
"$script" deployer/8 8.0.6 false "$work/body.md" > /dev/null
check 'non-draft PR has no --draft' 'grep -q "pr create" "$work/gh.log" && ! grep -q -- "--draft" "$work/gh.log"'

exit $fails
```

- [ ] **Step 6: Run it to verify it fails**

Run: `in-composer bash tests/scripts/open-pr-test.sh`
Expected: it stops with an error or `FAIL:` lines, because `open-pr.sh` doesn't exist yet, and exits non-zero.

- [ ] **Step 7: Write `open-pr.sh`**

`.github/scripts/open-pr.sh`, then `chmod +x` it:

```bash
#!/usr/bin/env bash
# Usage: open-pr.sh <branch> <deployer-version> <draft: true|false> <body-file>
# Commits the working tree to <branch>, force-pushes it and opens or updates its pull request against main.
# Does nothing if <branch> on origin already bundles <deployer-version>, so daily runs don't churn the PR.
set -euo pipefail

branch=$1 version=$2 draft=$3 body_file=$4
title="Bundle Deployer $version"

bundled_version() {
    php -r 'echo json_decode(stream_get_contents(STDIN), true)["extra"]["deployer"]["version"] ?? "";'
}

if git fetch --quiet origin "refs/heads/$branch" 2>/dev/null \
    && [ "$(git show FETCH_HEAD:composer.json | bundled_version)" = "$version" ]; then
    echo "$branch already bundles Deployer $version; nothing to do."
    exit 0
fi

git switch --quiet -C "$branch"
git commit --quiet -am "$title"
git push --quiet --force origin "HEAD:refs/heads/$branch"

number="$(gh pr list --head "$branch" --state open --json number --jq '.[0].number // empty')"
if [ -n "$number" ]; then
    gh pr edit "$number" --title "$title" --body-file "$body_file"
else
    args=(--base main --head "$branch" --title "$title" --body-file "$body_file")
    if [ "$draft" = true ]; then
        args+=(--draft)
    fi
    gh pr create "${args[@]}"
fi
```

- [ ] **Step 8: Run both script tests to verify they pass**

Run: `in-composer bash tests/scripts/open-pr-test.sh && in-composer bash tests/scripts/deployer-update-cli-test.sh`
Expected: eight `ok:` lines from the PR test and seven from the CLI test, exit 0.

- [ ] **Step 9: Commit**

```bash
git add .github/scripts/deployer-update.php .github/scripts/open-pr.sh tests/scripts/deployer-update-cli-test.sh tests/scripts/open-pr-test.sh
git commit -m "Add CLI and pull request helper for the Deployer update workflow" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Workflows

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `.github/workflows/deployer-update.yml`
- Modify: `.github/workflows/release.yml` (trigger, checkout ref, `TAG`, release title)

**Interfaces:**
- Consumes: every script from Tasks 1, 3, 5 and 6, by the exact paths and arguments listed in their Interfaces blocks.
- Produces:
  - `release.yml` becomes callable with `workflow_call` input `tag` (string, required).
  - Release titles become `<tag> (Deployer <version>)` whenever `composer.json` has `extra.deployer.version`.

- [ ] **Step 1: Baseline lint**

Workflows can't be unit-tested locally, so actionlint is the gate for this task.

Run: `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color=false; echo "exit=$?"`
Expected now: `exit=0`, since only `release.yml` exists. Run the same command after every step below; it must stay at `exit=0`. It checks workflow syntax, expressions, and every `run:` block with shellcheck.

- [ ] **Step 2: Write `ci.yml`**

```yaml
name: CI

on:
  push:
    branches:
      - '**'
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - name: Check out
        uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Validate composer.json
        run: composer validate --strict --no-check-lock

      - name: Install dependencies
        run: composer install --no-interaction --no-progress

      - name: Unit tests
        run: vendor/bin/phpunit

      - name: Verify bundled phar signature
        run: .github/scripts/verify-phar.sh bin/deployer.phar bin/deployer.phar.asc

      - name: Smoke test
        run: tests/smoke/run.sh

      - name: Script tests
        run: |
          tests/scripts/verify-phar-test.sh
          tests/scripts/deployer-update-cli-test.sh
          tests/scripts/open-pr-test.sh
```

- [ ] **Step 3: Write `deployer-update.yml`**

```yaml
name: Deployer update

on:
  schedule:
    - cron: '23 4 * * *'
  workflow_dispatch:
    inputs:
      dry_run:
        description: Only detect, verify and smoke-test; no commit, tag, push, pull request or release
        type: boolean
        default: false

concurrency:
  group: deployer-update
  cancel-in-progress: false

permissions:
  contents: write
  pull-requests: write

env:
  # Commits, tags, pushes, pull requests and releases only happen for real runs on main.
  PUBLISH: ${{ github.ref == 'refs/heads/main' && !inputs.dry_run }}

jobs:
  plan:
    runs-on: ubuntu-latest
    outputs:
      mode: ${{ steps.plan.outputs.mode }}
      same_major: ${{ steps.plan.outputs.same_major }}
      next_major: ${{ steps.plan.outputs.next_major }}
      recipes_version: ${{ steps.plan.outputs.recipes_version }}
      next_recipes_major: ${{ steps.plan.outputs.next_recipes_major }}

    steps:
      - name: Check out
        uses: actions/checkout@v4
        with:
          fetch-depth: 0 # the plan needs this repository's tags

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Plan
        id: plan
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          set -euo pipefail
          gh release list -R deployphp/deployer --exclude-pre-releases --exclude-drafts --limit 100 \
            --json tagName --jq '.[].tagName' > "$RUNNER_TEMP/upstream-tags"
          php .github/scripts/deployer-update.php plan < "$RUNNER_TEMP/upstream-tags" > "$RUNNER_TEMP/plan"
          cat "$RUNNER_TEMP/plan" >> "$GITHUB_OUTPUT"
          { echo '```'; cat "$RUNNER_TEMP/plan"; echo '```'; } >> "$GITHUB_STEP_SUMMARY"

  same-major:
    needs: plan
    if: needs.plan.outputs.mode == 'release' || needs.plan.outputs.mode == 'pr'
    runs-on: ubuntu-latest
    outputs:
      tag: ${{ steps.release.outputs.tag }}
    env:
      MODE: ${{ needs.plan.outputs.mode }}
      NEW_DEPLOYER: ${{ needs.plan.outputs.same_major }}
      RECIPES_VERSION: ${{ needs.plan.outputs.recipes_version }}
      GH_TOKEN: ${{ github.token }}

    steps:
      - name: Check out
        uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Bundle Deployer ${{ env.NEW_DEPLOYER }}
        run: |
          .github/scripts/fetch-phar.sh "$NEW_DEPLOYER" bin
          composer config extra.deployer.version "$NEW_DEPLOYER"

      - name: Test
        run: |
          composer validate --strict --no-check-lock
          tests/smoke/run.sh

      - name: Update changelog
        run: |
          if [ "$MODE" = release ]; then
            php .github/scripts/deployer-update.php release-changelog "$RECIPES_VERSION" "$NEW_DEPLOYER"
          else
            php .github/scripts/deployer-update.php unreleased-changelog "$NEW_DEPLOYER"
          fi
          git diff --stat

      - name: Configure git
        run: |
          git config user.name 'github-actions[bot]'
          git config user.email '41898282+github-actions[bot]@users.noreply.github.com'

      - name: Commit, tag and push ${{ env.RECIPES_VERSION }}
        id: release
        if: env.MODE == 'release' && env.PUBLISH == 'true'
        run: |
          git commit --quiet -am "Bundle Deployer $NEW_DEPLOYER"
          git tag "$RECIPES_VERSION"
          git push --atomic origin HEAD:main "refs/tags/$RECIPES_VERSION"
          echo "tag=$RECIPES_VERSION" >> "$GITHUB_OUTPUT"

      - name: Open pull request
        if: env.MODE == 'pr' && env.PUBLISH == 'true'
        run: |
          {
            echo "Deployer $NEW_DEPLOYER was released ([release notes](https://github.com/deployphp/deployer/releases/tag/v$NEW_DEPLOYER))."
            echo
            echo "It was not released automatically because \`[Unreleased]\` in CHANGELOG.md has unreleased changes,"
            echo 'so the version number has to be chosen by hand. Merge this pull request, then release as usual.'
            echo
            echo 'CI does not run on pull requests opened by this workflow; the smoke test passed in the workflow run.'
          } > "$RUNNER_TEMP/pr-body.md"
          .github/scripts/open-pr.sh "deployer/${NEW_DEPLOYER%%.*}" "$NEW_DEPLOYER" false "$RUNNER_TEMP/pr-body.md"

  next-major:
    needs: plan
    if: needs.plan.outputs.next_major != ''
    runs-on: ubuntu-latest
    env:
      NEW_DEPLOYER: ${{ needs.plan.outputs.next_major }}
      NEXT_RECIPES_MAJOR: ${{ needs.plan.outputs.next_recipes_major }}
      GH_TOKEN: ${{ github.token }}

    steps:
      - name: Check out
        uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Bundle Deployer ${{ env.NEW_DEPLOYER }}
        run: |
          .github/scripts/fetch-phar.sh "$NEW_DEPLOYER" bin
          composer config extra.deployer.version "$NEW_DEPLOYER"
          composer config extra.branch-alias.dev-main "$NEXT_RECIPES_MAJOR.x-dev"
          php .github/scripts/deployer-update.php unreleased-changelog "$NEW_DEPLOYER"

      - name: Test
        id: test
        run: |
          set +e
          { composer validate --strict --no-check-lock && tests/smoke/run.sh; } > "$RUNNER_TEMP/test.log" 2>&1
          echo "status=$?" >> "$GITHUB_OUTPUT"
          cat "$RUNNER_TEMP/test.log"

      - name: Configure git
        run: |
          git config user.name 'github-actions[bot]'
          git config user.email '41898282+github-actions[bot]@users.noreply.github.com'

      - name: Open draft pull request
        if: env.PUBLISH == 'true'
        env:
          TEST_STATUS: ${{ steps.test.outputs.status }}
        run: |
          if [ "$TEST_STATUS" = 0 ]; then result=passed; else result="failed (exit $TEST_STATUS)"; fi
          {
            echo "Deployer $NEW_DEPLOYER is a new major version ([release notes](https://github.com/deployphp/deployer/releases/tag/v$NEW_DEPLOYER))."
            echo "This draft bundles it for recipes $NEXT_RECIPES_MAJOR.x; the recipes most likely need changes before it can be released."
            echo
            echo "Smoke test: **$result**"
            echo
            echo 'CI does not run on pull requests opened by this workflow. Push a commit to this branch to trigger it.'
            echo
            echo '<details><summary>Smoke test log</summary>'
            echo
            echo '```'
            tail -n 50 "$RUNNER_TEMP/test.log"
            echo '```'
            echo '</details>'
          } > "$RUNNER_TEMP/pr-body.md"
          .github/scripts/open-pr.sh "deployer/${NEW_DEPLOYER%%.*}" "$NEW_DEPLOYER" true "$RUNNER_TEMP/pr-body.md"

  release:
    needs: same-major
    if: needs.same-major.outputs.tag != ''
    uses: ./.github/workflows/release.yml
    with:
      tag: ${{ needs.same-major.outputs.tag }}
```

- [ ] **Step 4: Make `release.yml` callable and add the Deployer version to titles**

Four edits to `.github/workflows/release.yml`:

(a) Extend `on:`:

```yaml
on:
  push:
    tags:
      - '**'
  # Tags pushed by the Deployer update workflow don't trigger the push event, so it calls this workflow directly.
  workflow_call:
    inputs:
      tag:
        description: Tag to release
        required: true
        type: string
```

(b) In the "Check out tagged revision" step, replace `ref: ${{ github.ref }}` with:

```yaml
          ref: ${{ inputs.tag || github.ref }}
```

(c) In the "Create or update release" step's `env`, replace `TAG: ${{ github.ref_name }}` with:

```yaml
          TAG: ${{ inputs.tag || github.ref_name }}
```

(d) Replace the last line of that step's script, `gh release create "${TAG}" --title "${TAG}" --notes-file "${notes_file}"`, with:

```bash
          title="${TAG}"
          deployer_version="$(jq -r '.extra.deployer.version // empty' composer.json)"
          if [[ -n "${deployer_version}" ]]; then
            title="${TAG} (Deployer ${deployer_version})"
          fi

          gh release create "${TAG}" --title "${title}" --notes-file "${notes_file}"
```

- [ ] **Step 5: Lint**

Run: `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest -color=false; echo "exit=$?"`
Expected: no findings, `exit=0`.

- [ ] **Step 6: Run the full local test suite once**

Everything that `ci.yml` runs, in order:

```bash
in-composer composer validate --strict --no-check-lock
php83 vendor/bin/phpunit
.github/scripts/verify-phar.sh bin/deployer.phar bin/deployer.phar.asc
in-php83 tests/smoke/run.sh
tests/scripts/verify-phar-test.sh
in-composer bash tests/scripts/deployer-update-cli-test.sh
in-composer bash tests/scripts/open-pr-test.sh
```

Expected: each command exits 0.

- [ ] **Step 7: Commit**

```bash
git add .github/workflows/ci.yml .github/workflows/deployer-update.yml .github/workflows/release.yml
git commit -m "Add CI and a scheduled workflow that bundles new Deployer releases" -m "Same-major Deployer releases are bundled, tagged and released automatically; a new Deployer major, or unreleased changes on main, lead to a pull request instead. release.yml is called directly because tags pushed with GITHUB_TOKEN don't trigger workflows." -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Docs and upgrade notes

**Files:**
- Modify: `README.md` (sections "Requirements" and "Install"; new section "Bundled Deployer" after "Install")
- Modify: `CHANGELOG.md` (`[Unreleased]`: add `### Added`, rewrite the first `### Changed` bullet)

**Interfaces:**
- Consumes: behavior from Tasks 2, 4 and 7.
- Produces: user-facing docs. No code.

- [ ] **Step 1: Replace "Requirements" and "Install" in `README.md`**

Replace everything from `## Requirements` up to (not including) `## Usage with Contao 4.13+` with:

````markdown
## Requirements

- PHP 8.3 or later in the environment you run `dep` in (e.g. your ddev web container)

Deployer is bundled with this package (see [Bundled Deployer](#bundled-deployer)),
so its dependencies never conflict with your project's.

Projects that cannot use PHP 8.3 yet can stay on version 1.15 of this package, which uses Deployer 7:
```shell
composer require --dev heimrichhannot/deployer-recipes:~1.15.0
```

> [!NOTE]
> Deployer 8 rewrote its TYPO3 recipe (own `deploy` task, optional rsync, different shared and writable directories).
> Check your `deploy.php` against the [upstream recipe](https://deployer.org/docs/8.x/recipe/typo3) when upgrading a TYPO3 project.

## Install

```shell
composer require --dev heimrichhannot/deployer-recipes
```

This installs Deployer as `vendor/bin/dep`:
```shell
php vendor/bin/dep deploy production
```

Do not require `deployer/deployer` alongside this package; the two conflict.
When upgrading from a version that used it, remove it first:
```shell
composer remove --dev deployer/deployer
```

## Bundled Deployer

This package ships the official Deployer phar, verified against the Deployer sign key.
The bundled version is recorded in `composer.json` under `extra.deployer.version`; `vendor/bin/dep --version` prints it.

A scheduled workflow bundles new Deployer releases:

| Deployer release        | Release of this package                  |
|-------------------------|------------------------------------------|
| patch (8.0.5 → 8.0.6)   | patch, released automatically            |
| minor (8.0.x → 8.1.0)   | minor, released automatically            |
| major (8 → 9)           | new major, prepared as a pull request    |

The recipes refuse to run under a Deployer major version other than the bundled one.
If you see "requires Deployer 8, but was loaded by Deployer …", run `vendor/bin/dep` instead of a globally installed `dep`.

````

- [ ] **Step 2: Update `[Unreleased]` in `CHANGELOG.md`**

Insert directly below `## [Unreleased]` (before the existing `### Changed`):

```markdown
### Added

- Bundle the Deployer 8.0.5 phar and expose it as `vendor/bin/dep` (see "Bundled Deployer" in the README).
- Refuse to run under a Deployer major version other than the bundled one, with a hint to use `vendor/bin/dep`.
- Scheduled workflow that bundles new Deployer releases and releases them automatically; Deployer majors arrive as a pull request.
- CI with unit, smoke and signature tests.

```

Then replace the first bullet under `### Changed`:

```markdown
- Require Deployer 8 (`deployer/deployer: ^8.0`) and PHP 8.3 or later.
  Projects on older PHP versions can stay on `~1.15.0`, which uses Deployer 7.
```

with:

```markdown
- **Upgrade:** `deployer/deployer` is no longer a dependency and now conflicts with this package.
  Run `composer remove --dev deployer/deployer` before updating if your project requires it directly; `deploy.php` stays unchanged.
  Use `vendor/bin/dep` instead of a globally installed `dep`.
- Require PHP 8.3 or later. Projects no longer need Symfony 7.4 or later, so Contao 4.13 and 5.3 projects can install this package.
  Projects on older PHP versions can stay on `~1.15.0`, which uses Deployer 7.
```

The remaining `### Changed`, `### Fixed` and `### Removed` bullets stay unchanged.

- [ ] **Step 3: Check the docs**

Run: `php83 -r 'require ".github/scripts/DeployerUpdate.php"; var_dump(HeimrichHannot\DeployerRecipes\Ci\DeployerUpdate::unreleasedIsEmpty(file_get_contents("CHANGELOG.md")));'`
Expected: `bool(false)`. The `[Unreleased]` heading is still parseable.

Run: `grep -n 'deployer/deployer' README.md`
Expected: only the "Do not require" sentence and the `composer remove` command.

- [ ] **Step 4: Commit**

```bash
git add README.md CHANGELOG.md
git commit -m "Document the bundled Deployer and the upgrade path" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Manual verification and first release (human, not for agents)

These steps need real projects, a stage host, GitHub settings or pushes. They belong to the user, not to an implementing agent. They are here so that nothing is forgotten.

- [ ] **Real projects (success criterion 1).** Push `feature/deployer-v8`. In one Contao 4.13 and one Contao 5.3 project (ddev, PHP 8.3), run `composer remove --dev deployer/deployer` if the project has it. Then add `{"type": "vcs", "url": "git@github.com:heimrichhannot/deployer-recipes.git"}` to `repositories` and run `composer require --dev heimrichhannot/deployer-recipes:dev-feature/deployer-v8`. Expected: the install succeeds, and `ddev exec php vendor/bin/dep list` lists the recipe tasks.
- [ ] **Real deployment (success criterion 2).** From one of those projects, run `php vendor/bin/dep deploy` against a stage host.
- [ ] **IDE.** In PhpStorm, open a consuming project's `deploy.php` and check that `Deployer\host()` and `set()` resolve via `vendor/heimrichhannot/deployer-recipes/bin/deployer.phar`. If they don't, add a README note on adding the phar to the PHP include path.
- [ ] **Repository setting.** Enable Settings → Actions → General → "Allow GitHub Actions to create and approve pull requests".
- [ ] **First release.** Merge into `main`. In `CHANGELOG.md`, rename `## [Unreleased]` to `## [2.0.0] - <date>` and add a new, empty `## [Unreleased]` heading above it; the workflow fails loudly without that heading. Commit, tag `2.0.0` and push. Expected: `release.yml` creates the release "2.0.0 (Deployer 8.0.5)".
- [ ] **Dry run of the update workflow.** Create branch `test/deployer-update` from `main`, run `composer config extra.deployer.version 8.0.4`, commit and push. Then run `gh workflow run deployer-update.yml --ref test/deployer-update -f dry_run=true`. Expected job summary: `mode=release`, `same_major=8.0.5`, `recipes_version=2.0.1`, with the `same-major` job green and no push, tag or PR. Delete the branch afterwards.
