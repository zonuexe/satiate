# ADR-0002: Use `composer/composer` as a Library Dependency

Satiate depends on `composer/composer` (in `require`, not just `require-dev`) for metadata fetching, dependency resolution, and dist archive downloading instead of reimplementing these or shelling out to the `composer` binary.

## Considered Options

- **A (chosen): Depend on `composer/composer` library** — reuse Composer's IoC container, SAT solver, repository implementations (Packagist, VCS, Composer API), and download manager. Large dependency but battle-tested and under active maintenance.
- **B: Reimplement from scratch** — full control but enormous effort; the Composer metadata protocol, SAT solving, and archive handling are non-trivial. No clear benefit for a tool whose job is to interoperate with Composer's ecosystem.
- **C: Shell out to `composer` binary** — avoids the dependency but introduces coupling to a specific installed binary version, complicates error handling, and prevents programmatic access to the resolved package graph.

## Consequences

- Users installing satiate will pull in `composer/composer` and its transitive dependencies. Given that satiate is a CLI tool for dev/CI environments (not a runtime library), this is acceptable.
- Satiate can tap into Composer's internal APIs (`Composer\Repository\RepositoryInterface`, `Composer\DependencyResolver\Solver`, etc.) directly, enabling precise control over the resolution and pruning steps.
