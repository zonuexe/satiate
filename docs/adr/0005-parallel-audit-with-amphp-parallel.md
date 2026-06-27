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

**Adopt option A — validated by measurement.** The prototype parallelises the audit with
`amphp/parallel` and the benchmark confirmed a clear win (below), so the dependency is kept.
Both `satiate audit` and `satiate build` gained an opt-in `--jobs=N`. Parallelising
`downloadDistArchives()` is **deferred**: if download time proves significant, the preferred
route is driving Composer's own `Loop`/`HttpDownloader` rather than adding an independent
event loop.

Stylistically, `amphp/amp` v3 (Fiber-based, written in a synchronous style on
`revolt/event-loop`) fits this codebase's modern-PHP, strict-types posture better than
ReactPHP's callback/Promise idiom — so if any event-loop code is later warranted, the amp
stack is preferred over ReactPHP.

### Default: `--jobs=1` (opt-in, like `make -j`)

Parallelism is **off by default**. A build tool should be reproducible and well-behaved on
shared CI, and `nproc`/`hw.ncpu` does not reflect cgroup CPU quotas — auto-detecting cores
would happily spawn 12 workers inside a 1-CPU container and thrash. So `--jobs` defaults to
`1` (byte-identical to the pre-parallel behaviour) and the user opts into `-j N`, exactly as
`make` does. No `auto` keyword for the same reason: the safe core count is the caller's call,
not a guess satiate makes.

### Scheduling: size-balanced, oversubscribed batches

The pool reuses a fixed set of workers, so one task per target adds a channel round-trip per
target and stops scaling once workers are saturated. Instead targets are bundled into batches
balanced by file size (longest-processing-time-first), with **~3× as many batches as workers**
so the pool dynamically pulls a fresh batch whenever a worker frees up. One batch per worker
was measurably worse at low `--jobs` (static imbalance); oversubscription fixed that while
keeping the high-`--jobs` win.

## Measured results

Local mirror built from installed packages (211 dist archives), median of 3 runs, 12-core
NTS host. Sequential baseline (`--jobs=1`): **25.2 s**, one core at ~99%.

| `--jobs` | one task/target | size-balanced batches ×3 |
|---|---|---|
| 4  | 9.5 s | 8.6 s |
| 8  | 8.8 s | **6.5 s** |
| 12 | 8.8 s | 7.1 s |

Best ≈ **3.8× (25.2 s → 6.5 s at `--jobs=8`)**. The batched schedule is ≥ the per-target one
at every job count. Speedup plateaus around `--jobs=8`: a few dominant archives set an Amdahl
floor and archive extraction contends on disk I/O, so 12 cores do not yield 12×. This is a
property of the corpus, not the implementation — more workers past ~8 do not help.

## Consequences

- A new production dependency: `amphp/parallel` (pulls in `amphp/amp` and `revolt/event-loop`).
- Audit parallelism is opt-in and bounded by `--jobs`; `--jobs=1` reproduces the exact
  sequential behaviour. Determinism holds because only parsing runs in parallel — findings come
  back keyed by path and the parent aggregates, fingerprints, caches, and emits them in the
  original order. Verified: `audit` output is byte-identical across job counts, and a `build`
  with `--jobs 8` writes the same `capability-fingerprints.json` as `--jobs 1`.
- The shared path runs through `Audit\Parallel`: `AuditTarget` (a path + how to audit it,
  carrying package/version), `AuditExecutor` (picks sequential vs pool), `ParallelAuditRunner`
  + `BatchAuditTask` (the pool and batching). `audit` and `build` both build `AuditTarget`s and
  call `AuditExecutor`.
- Worker startup and result serialisation add overhead, so very small mirrors can be faster at
  `--jobs=1`; that is the default, so nobody pays for parallelism they did not ask for.
- PHPStan runs at `level: max`; amp's generics are annotated precisely (the `Task` generic flows
  through `Worker::submit`) — no casts, `@var`, or ignores.
- Download parallelism remains unaddressed; revisit via Composer's internal downloader if
  measured build time warrants it.
