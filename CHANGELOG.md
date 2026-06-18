# Changelog

All notable changes to `devifyo/watchtower` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.1] - 2026-06-18

### Added

- **In-dashboard database setup** — when Watchtower's tables are missing, the
  dashboard now shows a "Set up database" screen with a one-click button that
  runs only Watchtower's migrations (great for first run and multi-tenant apps).
  New endpoints: `GET /api/setup/status` and `POST /api/setup/migrate`.

### Changed

- A missing-tables error now renders a clear, actionable message naming the
  database connection, instead of leaking a raw SQL exception to the dashboard.

### Fixed

- Multi-tenant footgun where the default connection switches per request: set
  `WATCHTOWER_DB_CONNECTION` to a stable central connection (now documented).

## [1.0.0] - 2026-06-18

### Added

- **Schedule monitoring** — tracks every scheduled task, records run history, and
  detects missed runs. Includes a "run now" action to trigger a scheduled command
  on demand from the dashboard.
- **Queue monitoring** — driver-agnostic monitoring of queues, jobs, and failures
  with native single and bulk retry of failed jobs.
- **Exception tracker** — built-in capture of application exceptions with fingerprint
  grouping of similar errors plus resolve and reopen workflows.
- **Production safety** — after-response writes to stay off the request hot path,
  configurable sampling, payload truncation, an optional separate database
  connection, and retention pruning of old records.
- **Alerts** — optional notifications for missed schedules, queue failures, and new
  or reopened exceptions.
- **Dashboard** — a compiled Vue single-page dashboard shipped with the package.

[Unreleased]: https://github.com/Devifyo/watchtower/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/Devifyo/watchtower/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Devifyo/watchtower/releases/tag/v1.0.0
