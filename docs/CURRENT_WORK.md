# Current work — snapshot

_Last updated: 2026-06-21_

A handoff note capturing the working state between sessions. Delete or rewrite freely; this is a
scratch status doc, not a spec.

## Status at a glance

| Gate | State |
|------|-------|
| PHPStan (`make lint`, level max + bleedingEdge, PHP 8.5) | **0 errors** (src + tests) |
| PHPUnit (`make test`) | **354 tests, 916 assertions — green** |
| ECS (`make cs`) | **clean** |
| `make dogfood` (HTTP E2E) | **green** — SUCCESS on this branch |
| Infection (`make infection`) | **NOT re-run** over the new `Audit/Parallel/*` code — see next steps |

Work is on branch **`experiment/parallel-audit`**, pushed, with **[PR #3](https://github.com/zonuexe/satiate/pull/3)** open against `master`
(4 commits: ADR-0005 + 3 implementation). `master` locally has the ADR-add commit (`5ae7078`) that
`origin/master` does not, so the PR bundles it — intentional, keeps the whole feature reviewable.

## What this branch does — parallel audit (`--jobs`)

Parallelises the CPU-bound audit across worker processes, opt-in via `--jobs=N` on both
`satiate audit` and `satiate build`. Rationale and the measurement gate are in
**`docs/adr/0005-parallel-audit-with-amphp-parallel.md`** (read it first when resuming).

- **Why a worker pool, not an event loop.** The audit (AST-parsing every PHP file in every mirrored
  archive) is pure CPU and saturates one core (~25s, 99%). An event loop (ReactPHP / amphp+revolt)
  only overlaps I/O and does nothing here; parallelism across cores needs OS processes. PHP CLI is
  **NTS**, so `amphp/parallel` spawns process workers (no ZTS needed).
- **New dependency:** `amphp/parallel ^2` (pulls in `amphp/amp` v3 + `revolt/event-loop`).
- **Shared path** under `src/Audit/Parallel/`:
  - `AuditTarget` — a path + how to audit it (`AuditTargetKind`: php / composer-json / archive),
    carrying package/version so `build` can key capability fingerprints.
  - `AuditExecutor` — the single entry both commands use; `--jobs 1` runs inline, more uses the pool.
  - `ParallelAuditRunner` + `BatchAuditTask` — the pool and its scheduling; graceful `shutdown()`.
- **Determinism preserved:** only parsing runs in parallel. Findings come back keyed by path; the
  parent aggregates summary / fingerprints / cache / output in the original order.

### Two design decisions worth remembering

- **Default `--jobs=1` (opt-in, like `make -j`).** No `auto`: `nproc`/`hw.ncpu` ignores cgroup CPU
  quotas, so an auto default would oversubscribe inside containers. Caller picks the count.
- **Size-balanced, oversubscribed batches.** Pool reuses a fixed worker set, so one task per target
  adds a channel round-trip each and stops scaling once workers saturate. Targets are bundled into
  batches balanced by file size (LPT), with **~3× as many batches as workers** so the pool keeps
  every worker busy. One batch per worker was measurably worse at low `--jobs` (see ADR table).

### Measured (211-archive local mirror, median of 3, 12-core NTS host)

Sequential `--jobs=1` = 25.2s. Best ≈ **3.8× (→ 6.5s at `--jobs=8`)**. Plateaus ~`--jobs=8`: a few
dominant archives set an Amdahl floor + extraction I/O contends — a corpus property, not the impl.

### Verified

- `audit` output **byte-identical** across job counts (parity unit test + full-mirror diff).
- `build --jobs 8` writes the **same `capability-fingerprints.json`** + same 2716-finding summary as
  `--jobs 1`.

## Open items / next steps

- **Watch CI on [PR #3](https://github.com/zonuexe/satiate/pull/3).** Main risk is the PHP matrix: confirm `amphp/parallel ^2` and its deps
  install/resolve on every matrix PHP. (`composer.lock` updated; most of the PR's +1850 is the lock.)
- **Re-run `make infection`** over `src/Audit/Parallel/*` + the changed `AuditCommand` / `BuildCommand`
  / `BuildRunner` before trusting the MSI gate — the prior Infection run predates this code, so its
  numbers are stale. (Coverage driver caveats below still apply.)
- **`--jobs` default / `auto`:** revisit only if real usage wants core-count-by-default; current
  decision is deliberate opt-in (ADR-0005).
- **Download parallelism deferred:** if build download time matters, drive Composer's own
  `Loop`/`HttpDownloader` rather than adding a second event loop. Not started.
- **Before merge (optional):** decide whether to squash the 3 `experiment:` commits into one `feat:`
  and/or rename the branch. PR currently keeps them separate.

## Infection / coverage notes (important for resuming)

- **Coverage driver:** Infection needs PCOV or Xdebug. **PCOV is installed in the local Homebrew
  PHP's `php.ini`** — a machine-level change, *not* in the repo. CI / anyone else running
  `make infection` must install a coverage driver too. (phpdbg does NOT work with PHPUnit 13.)
- **Why `tools/infection/phpunit.xml.dist` exists:** the root `phpunit.xml.dist` is intentionally
  strict (`requireCoverageMetadata` / `failOnRisky`). Under coverage that marks many tests "risky" and
  Infection's `stopOnDefect` would stop at the first one and collect only partial coverage. Infection
  uses a dedicated relaxed config; `make test` keeps the strict root config. Don't "simplify" away.
- **Parallel code + mutation testing:** `BatchAuditTask::run()` executes only inside a worker process,
  so a coverage driver in the parent won't see it — expect Infection to report it uncovered. The same
  logic is exercised through `AuditExecutor`'s sequential path (`AuditTarget::auditWith`), which the
  parity tests cover; keep that in mind rather than contorting tests to cover the worker entrypoint.
