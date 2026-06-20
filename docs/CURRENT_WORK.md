# Current work — snapshot

_Last updated: 2026-06-20_

A handoff note capturing the working state between sessions. Delete or rewrite freely; this is a
scratch status doc, not a spec.

## Status at a glance

| Gate | State |
|------|-------|
| PHPStan (`make lint`, level max + bleedingEdge, PHP 8.5) | **0 errors** |
| PHPUnit (`make test`) | **347 tests, 896 assertions — green** |
| ECS (`make cs`) | **clean** |
| Infection (`make infection`) | **green** — 934 mutants, **100% mutation code coverage**, **98% covered MSI** (gate 95), **98.5% MSI** (gate 90) |
| `make dogfood` (HTTP E2E) | **green** — installs mirror-only with Packagist disabled, so a broken mirror fails it |

Work is on branch **`fix/mirror-correctness-and-audit`** as a single commit (not yet pushed) — the
mirror-correctness + audit-overhaul described below. Push / open a PR when ready.

## What changed recently (this session)

Newest first:

- **(uncommitted) Mirror correctness + audit overhaul.** Made satiate actually work as a mirror and
  the dogfood a genuine E2E, then extended the audit:
  - **`versionMatchesConstraint()` keeps dev/branch versions.** `path`-repo packages report
    `dev-master` (derived from the enclosing branch); a `^8.1`-style constraint can never match, so
    they were dropped. This is the dogfood under-resolution noted below — now resolved.
  - **`dist.shasum` is SHA-1, not SHA-256.** Composer verifies dist checksums with `hash_file('sha1', …)`,
    so every download from a satiate mirror failed checksum verification. (`computeSha256` → `computeDistShasum`.)
  - **Dogfood rewritten to install mirror-only** (Packagist disabled) so a broken mirror fails the
    test instead of silently falling back to packagist.org; dropped the phantom `php-http/discovery`.
  - **`audit` inspects distribution archives** (`zip`, `tar`, `tar.gz`, `tar.bz2`) via the new
    `Auditor::auditArchive()`, shared with the build-time audit; added `Severity::rank()` and the
    `AuditSummary` value object.
  - **Severity controls:** `audit --min-severity`, a per-severity summary, `audit --fail-on` (CI
    gate), `build --fail-on` (reflect audit in the build exit code) and `build --no-audit-cache`
    (deterministic re-audit). README documents all commands/options; CHANGELOG `[Unreleased]` updated.
  - **Supply-chain detections** (also slugified archive filenames + `archive.format` validation +
    tar/tar.gz/tar.bz2 archive support along the way): decoder chains
    (`eval(gzinflate(base64_decode()))`), request-input→sink backdoors, process exec, FFI/`dl`/`ini_set`
    tampering, network egress + C2/exfil host literals + sensitive-path recon, stream-wrapper funcs,
    PSR-1 mixed content, string-arg `assert`, **composer.json install hooks / plugin / autoload.files**,
    and **cross-version "sudden capability change"** (`VersionCapabilityDiff`, advisory). Auditing is
    robust against first-class-callable syntax (`foo(...)`). All detections tuned to **zero
    false-positive criticals** on the clean dogfood mirror; every new line is 100% mutation-covered.
- **(uncommitted) BuildRunner unit tests + two fixes surfaced by mutation testing.** Added
  `tests/Build/BuildRunnerTest.php` (32 tests) covering the previously-untested largest source file
  via reflection — pure helpers (`isPlatformPackage`, `filterPackageNames`, `archive*`,
  `versionMatchesConstraint`, `applyVersionPruning`), the filesystem generators (`computeSha256`,
  `rmdir`, `generateIncludeFiles`/`ProviderFiles`/`PackagesJson`, `generateWebUi`) and
  `serializePackages`/`packageToArray`. This pushed **whole-project mutation code coverage to 100%**.
  Two changes fell out of it:
  - **Real bug fixed:** `BuildRunner::isPlatformPackage()` used `str_starts_with($name, 'php')`, which
    wrongly classified real vendor packages (`phpunit/phpunit`, `phpstan/phpstan`, `php-http/*`) as
    platform packages and dropped them from the mirror. Now delegates to Composer's anchored
    `PlatformRepository::isPlatformPackage()`. Side effect: `composer-installers` is now treated as a
    normal package (correct).
  - **Redundant code removed:** the explicit `mkdir` guard in `generateProviderFiles()` — `JsonFile::write()`
    creates the parent dir itself, so the guard was dead. Removing it dropped 6 equivalent mutants and
    kept the Infection gate at 95.
- `555d0b9` — **Harden test suite via mutation testing (Covered MSI 65% → 95%).** Strengthened tests
  to kill Infection's escaped mutants across Auditor, ConfigLoader, the Audit/Build/Lock commands,
  and LockAnalyzer. Fixed a **real bug** a mutant surfaced: `LockCommand::applyToSatisJson()` did not
  `return` after a write failure, so `"Updated …"` printed even when the write failed. Raised the gate
  to 90/95.
- `8ba3c78` — **Introduce Infection.** Added `.vendor-bin/infection` (bamarni bin layout),
  `bin/infection`, `infection.json5`, a `make infection` target, and command-definition tests
  (killed the 13 `configure()` mutants). Set the initial gate to 65/65.
- `3fd8809` — Documented the PHPStan house rules in `AGENTS.md` (fix at the root with PSL/`assert()`,
  never `@phpstan-ignore` / baseline / casts / local-var `@var` / level changes).
- `8b7522c` — Eliminated all PHPStan level-max errors via PSL validation (`Psl\Json\typed` +
  `Psl\Type` shapes, `assert()`, `instanceof` narrowing). Also fixed a latent bug:
  `BuildRunner::collectAllPackageNames()` called `getPackageNames()` on `RepositoryInterface`
  (only `ComposerRepository` has it) → now guarded with `instanceof`.

Dependency added earlier: `php-standard-library/php-standard-library` (PSL), used to validate decoded
`satis.json` / `composer.lock` at the boundary.

## Infection / coverage notes (important for resuming)

- **Coverage driver:** Infection needs PCOV or Xdebug. **PCOV 1.0.12 is installed in the local
  Homebrew PHP's `php.ini`** — this is a machine-level change, *not* in the repo. Anyone else (or CI)
  running `make infection` must install a coverage driver too. (phpdbg does NOT work: php-code-coverage
  dropped the phpdbg driver; PHPUnit 13 only ships PCOV/Xdebug drivers.)
- **Why `tools/infection/phpunit.xml.dist` exists:** the root `phpunit.xml.dist` is intentionally
  strict (`requireCoverageMetadata` / `beStrictAboutCoverageMetadata` / `failOnRisky`). Under coverage
  that marks many tests "risky", and Infection adds `stopOnDefect` to its generated config — so the
  coverage run would stop at the first risky test and collect only **partial** coverage (this made
  well-tested code look uncovered and inflated "escaped"). Infection therefore uses a dedicated
  relaxed config; `make test` keeps the strict root config. Don't "simplify" this away.
- **The ~22 still-escaping mutants are documented equivalents**, not test gaps — e.g. PSL
  `Type\shape(..., true)` allow-unknown-fields passthrough, php-parser treating a higher version as a
  syntax superset, redundant guard conditions, unreachable `mkdir(...) && is_dir(...)` failure guards
  (the throw only fires when `mkdir` fails, which a normal test env can't force; the `0755` permission
  bits are also behavior-neutral), and `count()` over array keys (value-independent). Forcing tests for
  these would be contrived.

## Open items / next steps

- **Commit** the uncommitted work (mirror/audit overhaul above + the earlier BuildRunner test work
  and `BuildRunner.php` fixes) when ready.
- **dogfood under-resolution — RESOLVED.** The `path` repos reported dev versions (`dev-master`) that
  `versionMatchesConstraint()` rejected against the `^8.1`/`^13.2`/`^2.2` constraints, so packages were
  dropped; a separate SHA-256-vs-SHA-1 `dist.shasum` bug meant even included packages failed checksum
  verification on download. Both fixed; the dogfood now installs mirror-only with Packagist disabled
  and is green. (Note: `symfony/console`/`phpunit` cannot resolve mirror-only because their transitive
  caret-constrained deps are also `dev-master` — an inherent limit of dogfooding over an installed
  vendor tree — so they are asserted present in the mirror rather than installed from it.)
- **Re-run `make infection`** over the new audit/build code before relying on the MSI gate.
- **Coverage breadth:** whole-project mutation code coverage is now **100%** — every mutable line in
  `src` is exercised. Remaining lever is killing genuine equivalents only by simplifying code (as was
  done for `generateProviderFiles`), not by adding tests.
- **CI:** `make build` (`lint cs test dogfood`) and `.github/workflows/ci.yml` do **not** include
  `make infection` (it is slow and needs a coverage driver). Decide whether CI should enforce the MSI
  gate.
