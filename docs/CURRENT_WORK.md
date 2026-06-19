# Current work — snapshot

_Last updated: 2026-06-20_

A handoff note capturing the working state between sessions. Delete or rewrite freely; this is a
scratch status doc, not a spec.

## Status at a glance

| Gate | State |
|------|-------|
| PHPStan (`make lint`, level max + bleedingEdge, PHP 8.5) | **0 errors** |
| PHPUnit (`make test`) | **119 tests, 375 assertions — green** |
| ECS (`make cs`) | **clean** |
| Infection (`make infection`) | **Covered MSI 95%** (342/358 mutants killed); gate `minMsi 90 / minCoveredMsi 95` |
| `make dogfood` (HTTP E2E) | **not run this session** (needs a free port / network) |

Branch `master` has local commits **not yet pushed to `origin/master`** (the Infection work below,
plus this note). Run `git push` when ready.

## What changed recently (this session)

Newest first:

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
- **The 16 still-escaping mutants are documented equivalents**, not test gaps — e.g. PSL
  `allow-unknown-fields` passthrough, php-parser treating a higher version as a syntax superset,
  redundant guard conditions, `count()` over array keys (value-independent). Forcing tests for these
  would be contrived.

## Open items / next steps

- **Push** the 2 local commits when ready (`git push`). Nothing has been pushed this session.
- **Coverage breadth:** mutation testing only mutates *covered* code. Largely-uncovered files (e.g.
  much of `src/Build/BuildRunner.php`) generate few/no mutants, so a high MSI does not mean high line
  coverage. Writing tests for the uncovered logic is the next lever; raise `minMsi` as it improves.
- **CI:** `make build` (`lint cs test dogfood`) does **not** include `make infection` (it is slow and
  needs a coverage driver). Decide whether CI should enforce the MSI gate.
- **dogfood E2E** was not run this session — run `make dogfood` (or full `make build`) in an
  environment with a free port before relying on a green build.
