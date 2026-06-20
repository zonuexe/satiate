---
name: satiate-release-prep
description: |-
  Use this skill to release a new version of Satiate (the `satiate` PHP CLI). Trigger whenever the user wants to cut, ship, publish, or put out a new version — whether they say it in English or Japanese (リリース, 公開, バージョン切る, タグ打つ, タグ打って, 出したい), and whether they spell out the whole flow or just say "release it," "ship the next version," "あとよろしく," or "手順あったよね、それで進めて." Also trigger for any single step of the release: bumping the version number to vX.Y.Z, writing or moving CHANGELOG entries for a release, tagging a version, or creating the GitHub release.

  The workflow updates the VERSION constant and its test, writes the CHANGELOG (Keep a Changelog style), runs the lint/cs/test verification gates, then commits, tags, and publishes a GitHub release.

  Do NOT trigger for non-release work: fixing a bug in version output, correcting a CHANGELOG typo, adding command features, debugging failing tests, or general questions about semantic versioning.
---

# Satiate Release Prep

Follow this workflow when releasing a new `satiate` version. The goal is a tagged
commit whose `CHANGELOG.md` and reported version line up exactly with a GitHub
release, so anyone can trace a binary back to its source and its notes.

Decide the next [Semantic Version](https://semver.org/) first (pre-1.0, so breaking
changes are allowed in minor bumps), then move through the steps in order.

## Update Release Metadata

Update these files together so the working tree never advertises a version it can't
back up:

- `CHANGELOG.md`
- `src/Application.php` — the `VERSION` constant
- `tests/ApplicationTest.php` — the assertion that pins `getVersion()`

Do **not** add a `version` key to `composer.json`. Packagist derives the package
version from the git tag, and a hardcoded one only drifts. This is deliberate, not
an oversight.

### CHANGELOG.md

The changelog is the source of truth for the release notes, so write it for humans
reading the GitHub release, not for a machine diffing commits. It follows
[Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).

- Add a new `## [x.y.z] - YYYY-MM-DD` section directly below `## [Unreleased]`, using
  the real release date.
- Move the relevant notes out of `[Unreleased]` into the new section. Group them
  under the Keep a Changelog headings as needed: `Added`, `Changed`, `Deprecated`,
  `Removed`, `Fixed`, `Security`. For a brand-new feature surface, a single `Added`
  list is fine.
- Keep entries user-facing. Describe what a user of the `satiate` CLI can now do
  (commands, options, output formats), not internal refactors, type fixes, or test
  hardening — those don't belong in a changelog unless they change behavior.
- Update the link references at the bottom: point `[Unreleased]` at
  `compare/vX.Y.Z...HEAD` and add `[X.Y.Z]` pointing at the release tag. Keeping
  these consistent is what makes every version heading clickable.
- Preserve the Keep a Changelog / SemVer note at the top of the file.

### Version constant and its test

`src/Application.php` hardcodes the version Symfony Console reports for
`satiate --version`:

```php
public const string VERSION = 'x.y.z';
```

`tests/ApplicationTest.php` asserts that exact string. If you bump one without the
other, `make test` fails — that coupling is intentional, a tripwire so the reported
version can't silently drift from the release. Update both to the new version.

## Verify the Release

Run the standard gates before committing. They must all be green:

```bash
make lint   # PHPStan level max + bleedingEdge — 0 errors
make cs     # EasyCodingStandard — clean
make test   # PHPUnit — all green, including the version assertion above
```

`make build` additionally runs `make dogfood`, an HTTP end-to-end test that needs a
free port and network. Run it when the environment allows
(`make build` runs `lint cs test dogfood` together); if a port isn't available, run
the three gates above and say so plainly rather than claiming a full green build.

`make infection` (mutation testing) is **not** a release gate — it is slow and needs
a coverage driver (PCOV/Xdebug). Don't block a release on it.

If verification surfaces non-version cleanup (formatting, a real bug), commit that
separately first. Keep the version bump commit focused on metadata.

## Commit, Tag, and Publish

Make the release-prep commit with the changelog + version bumps together:

```bash
git add CHANGELOG.md src/Application.php tests/ApplicationTest.php
git commit -m "Bump up version to x.y.z"
```

Push the commit, then create and push an annotated tag:

```bash
git push origin master
git tag -a vX.Y.Z -m "Satiate X.Y.Z"
git push origin vX.Y.Z
```

The tag must point at a commit that exists on the remote — `gh release create`
tags against the pushed history, so push the commit first.

> **Pushing to `master` may be blocked.** A direct push to the default branch can be
> denied by branch protection or the agent's permission guard. If so, get the user's
> explicit authorization to push to `master`, or route the release-prep commit
> through a pull request and tag `master` after it merges. Don't work around the
> block silently.

Create the GitHub release, using the new `CHANGELOG.md` section as the notes:

```bash
gh release create vX.Y.Z \
  --repo zonuexe/satiate \
  --title "vX.Y.Z" \
  --notes-file <notes.md> \
  --verify-tag \
  --latest
```

Build `<notes.md>` from the `[X.Y.Z]` changelog section (drop the heading) so the
release page and the changelog tell the same story. `--verify-tag` refuses to create
a release for a tag that didn't reach the remote, catching a forgotten tag push.

After publishing, confirm with `gh release view vX.Y.Z --repo zonuexe/satiate` and
share the release URL.

## Quick Checklist

- Working tree starts clean, or you understand every pending change.
- `CHANGELOG.md` has a dated `[x.y.z]` section with user-facing notes and updated
  bottom links.
- `Application::VERSION` and the `ApplicationTest` assertion both match the new
  version.
- `composer.json` still has no `version` key.
- `make lint`, `make cs`, `make test` are green (note `make dogfood` if you couldn't
  run it).
- Commit message is `Bump up version to x.y.z`.
- Annotated tag `vX.Y.Z` is pushed and the GitHub release is published with notes
  from the changelog.
