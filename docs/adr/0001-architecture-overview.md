# ADR-0001: Architecture Overview

Satiate is a CLI tool (`satiate`) that replaces `composer/satis` as a Composer repository builder. It reads a `satis.json` config file, resolves dependencies via `composer/composer`'s SAT solver, applies configurable version pruning to avoid collecting unnecessarily old releases, and outputs static files (Composer 2.0 metadata format, dist archives, and a static WebUI) to a filesystem directory for distribution via Apache or other web servers.

## Commands

- `satiate build` — primary command; resolves dependencies, downloads dist archives, runs audit (unless `--no-audit`), generates metadata and WebUI
- `satiate audit` — standalone audit; change-diff + AST analysis detecting eval, obfuscated payloads, binary blob injection
- `satiate lock` — reads `composer.lock` files, runs `git blame` to assess reversion risk, and proposes/applies minimum version constraints

## Build process

1. Parse `satis.json` (Satis-compatible base, extended with satiate-specific keys)
2. Resolve the full dependency tree via `composer/composer` SAT solver
3. Apply version pruning to drop releases older than the configured threshold (mechanism TBD per real-world data)
4. Download dist archives for all retained packages (incremental — only missing or outdated archives)
5. Run audit on newly released/unseen versions (cache lives in `.satiate-cache/` in the output dir)
6. Generate `packages.json`, provider files, and static WebUI (`index.html` with embedded CSS)

## Key characteristics

- **Incremental builds**: cached audit state and dist archives reduce work on subsequent runs
- **Authentication**: fully delegated to Composer's `auth.json` / SSH agent — no custom auth
- **Output**: single directory tree with `.satiate-cache/`, distributable as-is via any static file server
- **require-dev**: excluded by default, opt-in via `--include-dev`
- **PHP 8.5+**: aggressively uses modern PHP features (readonly classes, enums, asymmetric visibility, property hooks, strict types)
