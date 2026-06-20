# Current work — snapshot

_Last updated: 2026-06-20_

A handoff note capturing the working state between sessions. Delete or rewrite freely; this is a
scratch status doc, not a spec.

## Status at a glance

| Gate | State |
|------|-------|
| PHPStan (`make lint`, level max + bleedingEdge, PHP 8.5) | **0 errors** |
| PHPUnit (`make test`) | **151 tests, 481 assertions — green** |
| ECS (`make cs`) | **clean** |
| Infection (`make infection`) | **Covered MSI ~95.7%** (489/511 mutants killed), **mutation code coverage 100%**; gate `minMsi 90 / minCoveredMsi 95` — green |
| `make dogfood` (HTTP E2E) | **not run this session**; see the note under "Open items" — the local build resolves very few packages from the `path` repos (pre-existing, not a regression) |

Branch `master` is **in sync with `origin/master`**. The BuildRunner test work + bug fix below is
**uncommitted in the working tree** — commit when ready.

## What changed recently (this session)

Newest first:

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

- **Commit** the uncommitted BuildRunner test work + the two `BuildRunner.php` fixes when ready.
- **dogfood under-resolution (worth investigating):** running `bin/satiate build` against the dogfood
  `satis.json` resolves **only 1–2 packages** (just `nikic/php-parser` + one more); `symfony/console`,
  `phpunit/phpunit`, `phpstan/phpstan`, `php-http/discovery` are dropped. The `isPlatformPackage` fix
  *improved* this (1 → 2 packages) and is not the cause. The remaining drop looks like a
  version-constraint mismatch: the `path` repos report dev/non-semver versions that don't satisfy the
  `^8.1` / `^13.2` / `^2.2` constraints in `versionMatchesConstraint()`. Since the dogfood script does
  `composer install` of those packages under `set -e`, the full E2E likely **fails** today. Needs a
  dedicated look (either fix the constraint handling for path/dev versions, or adjust the dogfood
  fixture).
- **Coverage breadth:** whole-project mutation code coverage is now **100%** — every mutable line in
  `src` is exercised. Remaining lever is killing genuine equivalents only by simplifying code (as was
  done for `generateProviderFiles`), not by adding tests.
- **CI:** `make build` (`lint cs test dogfood`) and `.github/workflows/ci.yml` do **not** include
  `make infection` (it is slow and needs a coverage driver). Decide whether CI should enforce the MSI
  gate.
