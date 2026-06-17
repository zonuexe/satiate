# ADR-0004: Composer 1.x Output Format Compatibility

Satiate's `satiate build` command generates a static Composer repository output directory. This ADR documents which output files target Composer 1.x vs 2.x, and the decision to omit Composer 1.x–specific optimisations by default.

## Output Format Landscape

| Format Element | Composer 2.x | Composer 1.x | Purpose |
|---|---|---|---|
| `packages.json` (inline `packages`) | Optional, ignored | **Required** | Full package metadata |
| `packages.json` (`metadata-url` + `available-packages`) | **Required** | Ignored | Entry point for lazy provider loading |
| `p/<vendor>$<package>.json` | **Required** | Ignored | Split provider files (2.x protocol) |
| `include/all$<hash>.json` | Ignored | Optional | Split package data for large repos (1.x protocol) |

## Considered Options

- **A (current): Generate `include/` unconditionally** — Always produces `include/all$<hash>.json` and adds an `includes` key to `packages.json`. Composer 1.x clients benefit from split data; Composer 2.x ignores it. Adds ~1 file write per build regardless of repository size.

- **B (default): Omit `include/`, generate `packages.json` with inline `packages` only** — Simplest output: `packages.json` with both inline `packages` and `metadata-url`/`available-packages`. Composer 1.x reads the inline data; Composer 2.x reads from provider files. Single-file output, no overhead.

- **C: Config-driven `include/` generation** — Add a config key to `satis.json` (e.g., `"generate-includes": true`) that controls whether `include/` is produced. Default matches option B, opt-in matches A.

## Decision

**Adopt option A for now** but acknowledge that option C is the likely future direction. Rationale:

1. The `include/` overhead is trivial during build (one additional `file_put_contents` and a SHA-1 computation) and produces negligible disk use except for very large repositories with thousands of packages.
2. Generating `include/` costs us nothing now and prevents a potential compatibility surprise for early adopters who might still use Composer 1.x pipelines.
3. If real-world usage shows measurable build-time or storage impact, the config-key approach (option C) is a straightforward, non-breaking addition.

## Consequences

- Every build produces an `include/` directory with one file regardless of repository size.
- Composer 1.x clients that fetch `packages.json` and follow the `includes` key will load the same package data via `include/` instead of parsing the inline `packages` in `packages.json`.
- Users who want to skip `include/` generation cannot do so today. This is an acceptable gap until the feature is motivated by performance data.
- Removing `include/` generation in the future is a backward-compatible change (Composer 1.x falls back to inline `packages` without the `includes` key).
