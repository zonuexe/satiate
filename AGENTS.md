## Agent skills

### Issue tracker

Issues are tracked as local markdown files under `.scratch/<feature-slug>/`. See `docs/agents/issue-tracker.md`.

### Triage labels

Triage uses the default label vocabulary (needs-triage, needs-info, ready-for-agent, ready-for-human, wontfix). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout — one CONTEXT.md + docs/adr/ at the repo root. See `docs/agents/domain.md`.

### Release prep

Cutting a new version (version bump, CHANGELOG, tag, GitHub release) follows `skills/internal/satiate-release-prep/SKILL.md`.

### Static analysis

PHPStan runs at `level: max` with bleedingEdge (`make lint`). Reduce errors at the root, never by suppression: validate dynamic data (decoded JSON, `getOption()` results) with PSL — `Psl\Json\typed()` + `Psl\Type` shapes — and use `assert()` for simple narrowings. Do not add `@phpstan-ignore`, a baseline, `ignoreErrors`, blind casts, or local-variable `@var` (property docblocks for generic shapes are fine), and do not lower the level. When fixing PHPStan errors, follow the `phpstan-error-reduction` skill if available.
