# Bundled Deployer phar (recipes v2) — design

Date: 2026-09-28
Status: approved in brainstorming, pending written-spec review
Branch: `feature/deployer-v8` → released as `2.0.0`

## Goal

Make `heimrichhannot/deployer-recipes` installable in as many projects as possible by removing
`deployer/deployer` as a composer dependency and shipping Deployer as its official phar inside this package.

### Why

Deployer 8 requires `php ^8.3` and `symfony/console|process|yaml ^7.4 || ^8.0`. That blocks installation in
Contao 4.13 (Symfony 5.4) and Contao 5.3 LTS (Symfony 6.4) projects. The phar bundles its own dependencies
(8.0.5 ships `symfony/* v7.4.11`) and does not load the project's `vendor/autoload.php`, so none of it
touches the project's dependency tree.

### Constraints

- `dep` runs inside the project's ddev web container, on the project's PHP version.
- The Deployer 8 phar needs **PHP 8.3** at runtime. `bin/dep` checks for 8.2, but the bundled composer
  `platform_check.php` rejects 8.2 (verified: `Your Composer dependencies require a PHP version ">= 8.3.0"`).
  PHP 8.2 projects stay on recipes `~1.15` (Deployer 7 via composer).
- `dep` is started by an internal tooling repo. It needs no changes as long as it calls `vendor/bin/dep`.

### Success criteria

1. `composer require --dev heimrichhannot/deployer-recipes:^2.0` succeeds in a Contao 4.13 and a Contao 5.3
   project (PHP 8.3, ddev).
2. `php vendor/bin/dep deploy production` behaves as with Deployer 8 installed via composer; `deploy.php`
   stays unchanged.
3. New upstream Deployer 8.x releases are bundled and released without human involvement; Deployer 9 arrives
   as a draft PR.

### Rejected alternatives

- **`deployer/dist`**: archived since 2022, last release `v7.0.0-rc.4`, no Deployer 8.
- **Launcher that downloads the pinned phar on first run**: needs download, cache and hash code and fails
  offline on first use. Committing the phar removes all of that.
- **Separate `heimrichhannot/deployer-phar` package**: a second repo and release pipeline for a single consumer.
- **Pin in the tooling repo or per project**: the pin would drift from the recipe version it was tested with.

## 1. Package layout

### Files

| Path | Purpose |
|---|---|
| `bin/deployer.phar` | Unmodified upstream phar. Keeps the `.phar` extension so IDEs can index it. |
| `bin/dep` | Wrapper, exposed by composer as `vendor/bin/dep`. |
| `.github/deployer-sign-key.asc` | Public key used to verify upstream phars. |
| `tests/smoke/{contao,symfony,typo3}/deploy.php` | Smoke-test fixtures. |

`bin/dep`:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/deployer.phar';
```

Verified with Composer 2.10.2 and PHP 8.5: a package exposing this wrapper via `bin` yields a `vendor/bin/dep`
that prints `Deployer 8.0.5` and exits 0, whether invoked as `php vendor/bin/dep` or executed directly.
Requiring the phar in-process preserves `$argv` and exit codes (`dep nonexistent:task` exits 1).

### `composer.json`

```json
{
    "require": {
        "php": "^8.3",
        "ext-json": "*"
    },
    "conflict": {
        "deployer/deployer": "*"
    },
    "bin": ["bin/dep"],
    "extra": {
        "deployer": {
            "version": "8.0.5"
        }
    }
}
```

- `conflict` prevents two packages competing for `vendor/bin/dep` and two Deployer copies in one project.
- `extra.deployer.version` is the single machine-readable record of the bundled version. It is read by the
  update workflow, the release title and the runtime guard. No checksum is stored: git guarantees integrity
  of the committed file, and the workflow verifies the upstream GPG signature before committing.

### Runtime guard (`autoload.php`)

After the existing `DEPLOYER` check, compare the major version of `DEPLOYER_VERSION` (defined by
`Deployer::run()`, e.g. `8.0.5`) with the major version of `extra.deployer.version`, read from this package's
`composer.json`. On mismatch, throw:

> heimrichhannot/deployer-recipes requires Deployer 8, but was loaded by Deployer 7.4.0. Run `vendor/bin/dep`.

The expected major comes from `extra`, not a hardcoded constant, so a major-bump PR only changes the phar and
`extra`.

### `.gitattributes`

```
bin/deployer.phar binary
/tests export-ignore
/.github export-ignore
/docs export-ignore
```

## 2. Update workflow (`.github/workflows/deployer-update.yml`)

### Triggers

- `schedule`: daily.
- `workflow_dispatch` with input `dry_run` (boolean, default `false`).
- `concurrency: deployer-update` (no parallel runs).

### Step 1: detect

- `current` = `extra.deployer.version`; `major` = its major.
- Upstream releases: `gh release list -R deployphp/deployer --exclude-pre-releases --exclude-drafts`,
  keeping only strict `vX.Y.Z` tags.
- `same_major_latest` = highest `major.*.*` release. If it is newer than `current`, take the **auto path**.
- `next_major_latest` = highest release with a higher major. If it exists, take the **major path**.
- Both paths may run in the same workflow run. If neither applies, the run ends green.

### Step 2: fetch and verify (both paths)

1. Download `deployer.phar` and `deployer.phar.asc` from the upstream release.
2. Import `.github/deployer-sign-key.asc` into a temporary `GNUPGHOME`, then `gpg --verify`. Require a good
   signature **and** the signing key fingerprint `0C331EAD47B77AC7DCA31D99EFC847736630CF36`
   ("Anton Medvedev (Deployer Sign Key) <anton@deployer.org>", published on keys.openpgp.org), which is
   pinned in the workflow file.
3. Any failure aborts the run without committing.
4. Replace `bin/deployer.phar` and run `composer config extra.deployer.version X.Y.Z`.

### Step 3: smoke test

The checks live in `tests/smoke/run.sh`, run on PHP 8.3 (`shivammathur/setup-php`) by both
`.github/workflows/ci.yml` (`on: push`, `pull_request`) and the update workflow:

1. `composer validate --strict`
2. `php bin/dep --version` prints `Deployer <extra.deployer.version>`.
3. For each fixture in `tests/smoke/{contao,symfony,typo3}/`: `php bin/dep -f <fixture>/deploy.php list` and
   `php bin/dep -f <fixture>/deploy.php tree deploy` exit 0.

Fixtures import `../../../autoload.php`, call `recipe('<name>')` and define one dummy host, with no network
access. This proves that the recipes load and the task graph resolves. It does not test a real deployment.

The update workflow runs the script against its uncommitted working tree before committing, so a broken
update is never pushed.

### Step 4a: auto path (same major)

1. **Tag guard:** find the latest tag matching `<recipes-major>.*.*` (tags have no `v` prefix), where the recipes
   major comes from `composer.json` `extra.deployer.recipes-major`, not from a branch name. If none exists,
   skip with a notice. This prevents anything happening before `2.0.0` has been tagged by hand.
   The workflow targets the repository's default branch, whatever it is called.
2. **Unreleased-commits guard:** if `main` has commits since that tag (`git rev-list --count <tag>..HEAD`),
   humans have merged changes that aren't released yet and the bump level can't be inferred. Instead of
   releasing, push the update to branch `deployer/<major>` (e.g. `deployer/8`) and open or update a PR, with the
   same idempotent handling as the major path (step 4b), then stop the auto path. The workflow never reads
   `CHANGELOG.md` to decide anything. (Amended after implementation; this replaced a guard on the content of
   `## [Unreleased]`.)
3. **Version:** a Deployer patch change → recipes patch bump; a Deployer minor change → recipes minor bump.
4. **CHANGELOG:** insert below `## [Unreleased]`, or above the newest release if that heading is missing:

   ```markdown
   ## [2.3.2] - 2026-10-01

   ### Changed

   - Bundle Deployer 8.0.6 ([release notes](https://github.com/deployphp/deployer/releases/tag/v8.0.6)).
   ```

5. Commit as `github-actions[bot]` ("Bundle Deployer 8.0.6"), tag `2.3.2`, push `main` and the tag.
6. Call the release workflow (step 5).

### Step 4b: major path

1. Branch `deployer/<major>` (e.g. `deployer/9`), created from `main`. The branch is force-pushed on every
   run that finds a newer release in that major, so there is one PR per major, updated in place.
2. Changes: the phar, `extra.deployer.version`, and an `[Unreleased]` entry "Bundle Deployer 9.0.0".
3. Open a **draft** PR if none is open for the branch; otherwise update its body.
4. The PR body contains the smoke-test result (pass/fail plus log excerpt). Pushes and PRs made with
   `GITHUB_TOKEN` do not trigger other workflows, so CI will not run on the PR by itself.
5. Requires the repo setting "Allow GitHub Actions to create and approve pull requests".

### Step 5: release

- `release.yml` gains `on: workflow_call` with a required `tag` input, alongside the existing tag-push trigger.
  Tags pushed with `GITHUB_TOKEN` do not trigger workflows, so the update workflow calls it as a follow-up job.
- The release title becomes `<tag> (Deployer <version>)` for every release, with `<version>` read from
  `extra.deployer.version` at that tag.
- Packagist and the private Satis are notified by repository webhooks, which fire for bot pushes too.

### Dry run

With `dry_run: true`, steps 1–3 run, the version that would be released (or the PR that would be opened) is
printed, and all commits, tags, pushes, PRs and releases are skipped.

### Permissions

`contents: write`, `pull-requests: write`.

### Known limitation

GitHub disables scheduled workflows in public repositories after 60 days without repository activity and
notifies the owners by email. Accepted; no keepalive.

## 3. Docs, migration and first release

### README

- **Requirements:** PHP 8.3+ where `dep` runs. Deployer is bundled; remove the paragraph recommending
  `composer require --dev deployer/deployer`, which now conflicts.
- **Install and usage:** `composer require --dev heimrichhannot/deployer-recipes:^2.0`, then
  `php vendor/bin/dep deploy production`.
- **New section "Bundled Deployer":** `extra.deployer.version`, the bump mapping (Deployer patch → patch,
  minor → minor, major → new recipes major via PR), and automatic releases.
- Keep the note that PHP 8.2 projects stay on `~1.15`.

### CHANGELOG (`[Unreleased]`, becomes `[2.0.0]`)

- **Changed:** bundle the Deployer 8.0.5 phar as `vendor/bin/dep`, and drop the `deployer/deployer`
  dependency. Projects no longer need Symfony 7.4+.
- **Added:** runtime Deployer version guard, automated Deployer updates, smoke-test CI.
- **Upgrade note:** run `composer remove --dev deployer/deployer` if the project required it directly, then
  require `^2.0`; `deploy.php` stays unchanged. Use `vendor/bin/dep` instead of a global `dep`.

### First release (manual, once)

1. Merge `feature/deployer-v8` into `main`.
2. Rename `[Unreleased]` to `[2.0.0] - <date>`, commit, tag `2.0.0` and push. The human tag push triggers
   `release.yml`.
3. The update workflow takes over from there. The tag guard keeps it from releasing before step 2.

## Testing

| What | How |
|---|---|
| Recipes load, task graph resolves | `ci.yml` smoke fixtures on every push and PR |
| Update detection and verification | `workflow_dispatch` with `dry_run: true` on a test branch with `extra.deployer.version` set to `8.0.4`, which should detect `8.0.5` |
| Installable in real projects (success criterion 1) | Before tagging `2.0.0`: install the branch via a VCS repository into a Contao 4.13 and a Contao 5.3 project (ddev, PHP 8.3); `composer require` succeeds, `vendor/bin/dep list` works |
| Real deployment (success criterion 2) | One `vendor/bin/dep deploy` to a stage host |
| IDE indexing | Check whether PhpStorm indexes `vendor/heimrichhannot/deployer-recipes/bin/deployer.phar` for autocompletion in `deploy.php`; if not, document adding it to the include path |

## Out of scope

- Download mirror or source URL override.
- Deployer 8 patch updates for 2.x after a 3.0 release exists.
- Keepalive for the 60-day schedule pause.
- Changes to the internal tooling repo.
