# Watchtower — project guide for Claude Code

Watchtower is a **Composer/Laravel package** (PHP 8.2+, Laravel 11/12): a
production-safe, driver-agnostic dashboard for Laravel **scheduled tasks, queues
& jobs, and exceptions** — that also lets you *act* (retry/bulk-retry failed
jobs, run a schedule on demand, resolve/reopen errors). The dashboard is a
pre-compiled Vue 3 SPA shipped in `dist/`, served from a single Blade view at
`/watchtower`. It never touches the host app's frontend pipeline.

- Package name (Packagist): **`devifyo/watchtower`**
- Repos: `https://github.com/Devifyo/watchtower` (primary) — published on Packagist with the GitHub webhook enabled (new tags auto-sync).
- PSR-4: `Watchtower\` → `src/`. Service provider: `Watchtower\WatchtowerServiceProvider` (auto-discovered via `extra.laravel.providers`).
- Tests: `composer test` (Pest + Orchestra Testbench). Full suite is expected green.
- Local UI preview: `php vendor/bin/testbench serve` then visit `/watchtower`; seed sample data with `php vendor/bin/testbench watchtower:demo-seed --fresh`.

---

## How to publish / release this package (remember this)

When asked about releasing, publishing, versioning, or "how do users install it":

### Install model
- Users install via **Packagist**, not GitHub directly: `composer require devifyo/watchtower`.
- Publishing to GitHub alone does NOT make `composer require` work. The package
  must be on **packagist.org** (sign in with GitHub → Submit → paste repo URL),
  with the GitHub webhook enabled so new tags auto-sync. **This is already done**
  for `devifyo/watchtower` — pushes/tags sync automatically.

### Versions come from git tags
- Composer installs **tagged** versions, not loose commits. With no tag, users
  only get unstable `dev-main`, which most apps refuse to install.
- A tag marks a commit as a released version (semantic versioning `vMAJOR.MINOR.PATCH`).
- Release flow (run in the repo, after code is committed and pushed):
  ```bash
  git add .
  git commit -m "message"
  git push
  git tag v1.0.1            # mark current code as this version
  git push origin v1.0.1    # send the tag up → Packagist auto-publishes it
  ```
- Bump rules: bug fix → patch (`v1.0.1`), new feature → minor (`v1.1.0`),
  breaking change → major (`v2.0.0`).
- The tag's major version must satisfy the constraint users install with
  (`^1.0` needs a `v1.x.x` tag; a `v0.x` tag won't match).
- Add a matching `## [x.y.z]` section to `CHANGELOG.md` **before** tagging.

### composer.json must have
- `"name": "devifyo/watchtower"`, a `"license"` (MIT), PSR-4 autoload mapping to
  `src/`, and the service provider under `extra.laravel.providers` for
  auto-discovery. Verify with `composer validate --strict`.

### Pre-launch check
- Before announcing a release, install the package into a **fresh Laravel app**
  using the exact README commands to confirm the tag, autoload paths, and shipped
  `dist/` assets all work. (Locally, the Testbench preview above is the fast path.)

### End-user flow (kept at the top of the README)
```bash
composer require devifyo/watchtower
php artisan watchtower:install   # publishes config, runs migrations, publishes assets
# then visit /watchtower
```

---

## Repo conventions
- Compiled SPA assets in `dist/` are **committed and shipped** (do not gitignore
  `dist/`). Source is in `resources/js` + `resources/css`; rebuild with
  `npm run build` after frontend changes, then commit the updated `dist/`.
- `composer.lock`, `node_modules/`, and `workbench/database/*.sqlite` are gitignored.
- Commit authorship for this repo is **Devifyo**.
