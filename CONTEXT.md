# Satiate

A Composer repository builder CLI tool that replaces `composer/satis`. It collects repositories listed in a config file, resolves dependencies, and outputs static files for distribution via Apache or other web servers.

## Language

**Satiate**:
The CLI binary and the project name. Users invoke `satiate` (not `satis` or `sate`).
_Avoid_: satis, sate

**satis.json**:
The config file format, inherited from `composer/satis`. Satiate reads `satis.json` and extends it with additional keys for its own features.
_Avoid_: config, settings file

**WebUI**:
The built-in web interface served by satiate, allowing users to browse available packages interactively.
_Avoid_: GUI, dashboard, frontend

**Version age threshold**:
A configuration option to ignore packages older than a specified cutoff, preventing satiate from resolving unnecessarily old releases. The exact mechanism (absolute date, relative period, max version count, or combination) is to be determined based on real-world usage patterns—avoiding hosting e.g. every old minor release of PHPUnit or PHPStan.
_Avoid_: cutoff, min-age, prune threshold

**build**:
The primary CLI command. Reads `satis.json`, resolves dependencies, outputs static files, and runs audit unless `--no-audit` is given.

**audit**:
A CLI command and a sub-routine within `build`. Scans package codebases for suspicious changes as a supply-chain security measure. Uses change-diff analysis (only inspecting newly released versions since the last audit) combined with static analysis (AST parsing via `nikic/php-parser`) to detect suspicious patterns. Detected patterns include evaluation constructs (`eval()`, `create_function()`), encoded payload execution (`base64_decode` + `exec`/`system`), and binary blob injection. `assert()` additions are detected but treated with lower severity due to high false-positive rate. Audit cache lives in the output directory.
_Avoid_: security scan, vulnerability scan

**lock**:
A CLI command that reads `composer.lock` files and derives recommended minimum version constraints for `satis.json` configuration. Uses `git blame` on lock files to assess reversion likelihood—packages with a history of being rolled back are flagged as risky to raise the floor on. Supports `--dry-run` (report only) and apply (update `satis.json` directly) modes.

**Dependency resolution**:
Satiate uses `composer/composer`'s SAT solver to fully resolve the dependency tree for all required packages, but applies configurable version pruning to avoid collecting unnecessarily old releases.

**PHP language level**:
The project follows PHP's official support timeline per [php.net/supported-versions.php](https://www.php.net/supported-versions.php) — the minimum PHP version is whatever is currently receiving active or security support. The codebase aggressively uses typed properties, `readonly` classes/properties, `enum`, asymmetric visibility (`public private(set)`), property hooks, and `declare(strict_types=1)` in every file. The goal is to write idiomatic contemporary PHP with zero backward-compatibility debt.
_Avoid_: polyfills, shims, legacy patterns

**Output directory**:
```
output/
├── packages.json           ← Composer 1.x format entry point
├── include/               ← Split package data
├── p/                     ← Composer 2.x provider files
├── dist/                  ← Distribution archives (zip)
├── index.html             ← WebUI (static, generated HTML with embedded CSS)
└── .satiate-cache/        ← Audit cache
