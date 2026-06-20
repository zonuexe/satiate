# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`audit`: supply-chain heuristics.** New detections aimed at injected/backdoor
  code: multi-stage **decoder chains** (`eval(gzinflate(base64_decode(...)))`,
  Critical/Warning); string-argument `assert()` (eval on PHP < 8); **request input
  flowing directly into a dangerous sink** — `$_GET`/`$_POST`/`$_REQUEST`/
  `$_COOKIE`/`$_FILES`, `getallheaders()` or `php://input` reaching
  `eval`/`system`/`include`/`unserialize`/`extract`/a callable (Critical, the
  canonical backdoor shape); and **composer.json install surface** —
  shell commands (or inline `@php -r`) on auto-run hooks (`post-install-cmd`,
  `post-autoload-dump`, …) at Critical, the `composer-plugin` type at Warning,
  `autoload.files` at Info; and **native-code / runtime tampering** —
  `FFI::cdef`/`load`/`scope`, `dl()`, and `ini_set()` of security-relevant
  settings (`disable_functions`, `auto_prepend_file`, …) at Warning; **network
  egress** (`curl_exec`, `fsockopen`, `mail`, …) at Info; **known C2/exfiltration
  host literals** (Discord/Telegram webhooks, pastebin, `.onion`, …) at Critical;
  and **sensitive-path reconnaissance** (`/etc/passwd`, `/proc/self/environ`, …)
  at Warning.
  `audit` and the build-time audit now scan each package's `composer.json` too.
- **`build`: sudden-capability-change detection.** Each version's capability
  fingerprint (its Warning-or-worse finding patterns) is cached in
  `.satiate-cache`, and the build reports when a newly-mirrored version gains a
  capability — exec, eval, an install hook, FFI, … — that the previous version
  lacked. This is the classic "a patch release suddenly phones home" signal.
  Advisory only: it is printed for review and never changes the build exit code.
- **`audit`: stream-wrapper and process-execution detection.** Flags
  wrapper-capable file functions (`file_get_contents`, `file_put_contents`,
  `fopen`, `copy`, `file`, `readfile`) — at Info for the capability, escalated to
  Critical when a literal argument names a remote or dangerous wrapper scheme
  (`http://`, `ftp://`, `data://`, `phar://`, `expect://`, …; benign `php://`
  streams stay Info). Process-spawning detection now also covers `pcntl_exec`.
- **`audit`: PSR-1 mixed-content heuristic.** Flags files that both declare symbols
  (class/interface/trait/enum/function) and run a top-level side effect — a PSR-1
  violation that is a classic signature of code injected into an otherwise-pure
  class file. Reported at Info severity, since benign code (a trailing
  `class_alias()`, a `trigger_deprecation()`) trips it too.
- **`audit --min-severity` and a severity summary.** Auditing a mirror of real
  libraries surfaces many benign matches (dynamic includes, `exec`, …). `audit`
  now prints a per-severity breakdown (`N issue(s) found. (X critical, Y warning,
  Z info)`) and accepts `--min-severity info|warning|critical` to list only
  findings at or above a threshold while still counting the rest (and reporting
  how many were hidden).
- **`audit --fail-on info|warning|critical`** makes `audit` exit non-zero when any
  finding reaches the given severity, so it can be used as a CI gate. It is
  independent of `--min-severity` (which only controls what is listed); without
  `--fail-on` the exit code is unchanged by findings.
- **`build --fail-on info|warning|critical`** reflects the build-time audit in the
  build's exit code: the build exits non-zero (and prints the gate result) when a
  newly-audited package trips the threshold. Without `--fail-on` the build exit
  code is unchanged, and the build now reports a per-severity audit breakdown.
- **`build --no-audit-cache`** audits every package on every run instead of
  skipping versions recorded in `.satiate-cache`, so a `--fail-on` gate is
  deterministic when rebuilding into the same output directory in CI.

### Changed

- **`audit` now inspects distribution archives.** A built mirror stores package
  code as zip or tar archives under `dist/`, so `satiate audit --path <mirror>`
  used to find no loose `.php` files and report "No PHP files found" — auditing
  nothing. It now extracts and scans the PHP inside each archive (`zip`, `tar`,
  `tar.gz`, `tar.bz2`; findings are located as `<archive>/<internal/path.php>`),
  and the build-time and standalone audits share one implementation
  (`Auditor::auditArchive()`).

### Fixed

- **`build`: dist archives are now usable.** The `dist.shasum` recorded in the
  generated metadata was a SHA-256 digest, but Composer verifies it with SHA-1,
  so every download from a satiate mirror failed with "checksum verification of
  the file failed". The mirror now records SHA-1 digests.
- **`build`: archive filenames are slugified.** A path/VCS package built on a branch like
  `fix/foo` gets the version `dev-fix/foo`; the `/` leaked into the archive filename and dist
  URL, producing a bogus subdirectory and a 404 on download. Both the name and version now have
  any filename/URL-unsafe characters (`/`, `\`, spaces, …) flattened to `-`
  (e.g. `vendor-pkg-dev-fix-foo.zip`).
- **`build`: `archive.format` is validated.** An unsupported format is rejected when the config
  loads with a clear error (`expected one of: zip, tar, tar.gz, tar.bz2`) instead of surfacing
  later as an opaque archiver failure.
- **`build`: untagged path/VCS packages are no longer dropped.** Packages served
  from `path` (or other VCS) repositories without a release tag expose a
  dev/branch version (e.g. `dev-master`) that a numeric constraint such as `^8.1`
  can never satisfy, so they were silently filtered out of the mirror. Such
  dev-stability versions are now always kept.
- **Dogfood E2E now genuinely exercises the mirror.** `bin/dogfood-test` installs
  packages from the served repository with Packagist disabled, so a broken mirror
  fails the test instead of silently falling back to `packagist.org`.

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
