# ADR-0005: Parallelism Strategy — `amphp/parallel` Worker Pool for CPU-bound Audit

Satiate's `build` and `audit` commands spend most of their wall-clock time in two
places: downloading/creating dist archives (I/O) and scanning extracted package code
with `nikic/php-parser` (CPU). This ADR records how — and where — satiate introduces
parallelism, after evaluating `reactphp/event-loop` and the `amphp/amp` v3 +
`revolt/event-loop` stack.

## The distinction that drives the decision

An **event loop** (`revolt/event-loop`, `reactphp/event-loop`) gives *concurrency for
non-blocking I/O* on a single thread — it overlaps the *waiting* of many sockets, but
runs no two PHP statements at the same instant. It does **nothing** for CPU-bound work.

**Parallelism across CPU cores** in PHP requires multiple OS processes (or ZTS threads).
`amphp/parallel` provides exactly this: a supervised worker pool, built on
`revolt/event-loop`, that serialises a `Task` to a worker, runs it, and returns the
result.

These solve different problems, and satiate's dominant cost is the CPU-bound one.

## Hotspot analysis

| Step | Bound by | Parallelisable? | Notes |
|---|---|---|---|
| `resolvePackages()` (`BuildRunner`) | CPU, but sequential | No | Composer SAT solve; inherently ordered. Out of scope. |
| `downloadDistArchives()` (`BuildRunner`) | Network + disk | Partially | `ArchiveManager::archive()` is blocking and loop-unaware; **Composer already parallelises HTTP internally via `curl_multi`**. |
| `auditStep()` / `Auditor::auditArchive()` | **CPU** | **Yes, embarrassingly** | Each archive is independent: extract → AST-parse every `.php`. On a real mirror this is thousands of files and the **dominant cost**. |

The standalone `audit` command (`AuditCommand`) runs the same per-target AST loop and
benefits identically.

## Environment (verified on the dev host)

- **PHP 8.5 NTS** (Non-Thread-Safe) — `ext-parallel` (true threads) needs ZTS and is
  unavailable. `pcntl`/`posix` are present, so `amphp/parallel` uses **process** workers
  automatically. No ZTS rebuild required.
- **12 cores** — a real ceiling for the CPU-bound audit, not a token win.
- `AuditResult` is a closure-free `readonly` value object and `Severity` is an enum, so
  results **serialise cleanly across the worker boundary** with no redesign.

## Considered Options

- **A (chosen): `amphp/parallel` worker pool for the CPU-bound audit.** One `Task` per
  archive wrapping the existing `Auditor::auditArchive()`; pool sized to the core count.
  The parent collects results and performs all aggregation, cache writes, and ordered
  output **sequentially**, preserving today's determinism. Opt-in via a `--jobs=N` flag
  with a sequential fallback (`--jobs=1`), and gated on a measured speedup before the
  dependency is committed permanently.

- **B: A bare event loop (`reactphp/event-loop`, or `amphp/amp` + `revolt`) for I/O
  concurrency on downloads.** Rejected as the *primary* strategy: it leaves the dominant
  CPU-bound audit untouched, Composer already parallelises archive downloads via
  `curl_multi`, and `ArchiveManager::archive()` is blocking and not loop-aware — wiring it
  into a loop means re-implementing archive creation against an async HTTP client for
  little marginal gain.

- **C: Hand-rolled `pcntl_fork()` or a `symfony/process` pool.** Rejected: reinvents the
  worker supervision, task/result serialisation, back-pressure, and error propagation that
  `amphp/parallel` already provides, and fits the project's modern-PHP aesthetic worse than
  the Fiber-based amp stack.

- **D: Status quo (fully sequential).** The baseline the prototype is measured against.

## Decision

**Adopt option A, gated on measurement.** Build a prototype that parallelises the audit
step with `amphp/parallel`, benchmark it against the sequential baseline on a real mirror,
and keep it only if the wall-clock improvement justifies the dependency. Parallelising
`downloadDistArchives()` is **deferred**: if download time proves significant, the
preferred route is driving Composer's own `Loop`/`HttpDownloader` rather than adding an
independent event loop.

Stylistically, `amphp/amp` v3 (Fiber-based, written in a synchronous style on
`revolt/event-loop`) fits this codebase's modern-PHP, strict-types posture better than
ReactPHP's callback/Promise idiom — so if any event-loop code is later warranted, the amp
stack is preferred over ReactPHP.

## Consequences

- A new dependency (`amphp/parallel`, pulling in `amphp/amp` and `revolt/event-loop`) is
  introduced **only if the benchmark confirms a win**.
- Audit parallelism is opt-in and bounded by `--jobs`; `--jobs=1` reproduces today's exact
  sequential behaviour, and the cache/summary/output ordering stay deterministic because
  only the parsing runs in parallel — all aggregation happens in the parent.
- Worker startup and task/result serialisation add per-task overhead, so small mirrors may
  be faster sequentially; the flag defaults conservatively and small jobs can stay serial.
- PHPStan runs at `level: max`; amp's generic types must be annotated precisely rather than
  suppressed, consistent with the project's no-baseline policy.
- Download parallelism remains unaddressed for now; revisit via Composer's internal
  downloader if measured build time warrants it.
