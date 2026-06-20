# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] - 2026-06-20

Initial release of **Satiate**, a simple and robust static Composer repository
generator that aims to replace `composer/satis`. It reads a `satis.json` config,
resolves the full dependency tree, and writes static files for hosting behind
Apache or any other web server. Requires PHP 8.5 or later.

### Added

- **`build` command** — read `satis.json`, resolve the dependency tree with
  Composer's SAT solver, and generate a static repository:
  - Composer 2.x provider files under `p/` (with `uid` metadata for full 2.x
    client compatibility).
  - Composer 1.x compatible `packages.json` entry point and split `include/`
    package data.
  - Distribution archives (zip) under `dist/`, with configurable archive
    directory and format.
  - A built-in static **WebUI** (`index.html`) for browsing available packages.
  - Respects `require`, `require-all`, `require-dependencies`,
    `require-dev-dependencies`, and `max-versions-per-package` from `satis.json`,
    propagating constraints to filter transitive packages and prune
    unnecessarily old releases.
  - Options: `--config`/`-c`, `--output-dir`, `--no-audit`, `--include-dev`.
  - Runs `audit` automatically unless `--no-audit` is given.
- **`audit` command** — scan package source for suspicious code patterns as a
  supply-chain security measure, combining AST analysis (via `nikic/php-parser`)
  with change-diff tracking:
  - Detects evaluation constructs (`eval()`, `create_function()`), encoded
    payload execution (`base64_decode` combined with `exec`/`system`), and binary
    blob injection. `assert()` additions are flagged at a lower severity.
  - Caches audited versions in `.satiate-cache/` so subsequent runs only inspect
    newly released versions.
  - Options: `--config`/`-c`, `--path`, `--cache-path`.
- **`lock` command** — derive recommended minimum version constraints for
  `satis.json` from a `composer.lock`:
  - Uses `git blame` on the lock file to assess reversion likelihood, flagging
    revert-prone packages as risky to raise the floor on.
  - Supports `--dry-run` (report only) and apply (update `satis.json` in place).
  - Options: `--lock`, `--dry-run`, `--config`/`-c`.
- Licensed under `AGPL-3.0-or-later`.

[Unreleased]: https://github.com/zonuexe/satiate/compare/v0.0.1...HEAD
[0.0.1]: https://github.com/zonuexe/satiate/releases/tag/v0.0.1
