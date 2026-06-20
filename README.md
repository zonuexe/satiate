# Satiate

A simple and robust static Composer repository generator. It reads a `satis.json`
config, resolves the dependency tree, and writes static files (`packages.json`,
provider files under `p/`, and distribution archives under `dist/`) you can host
behind any web server. Requires PHP 8.5+.

## Usage

```console
$ bin/satiate build -c satis.json --output-dir public/
$ bin/satiate audit --path public/
$ bin/satiate lock --lock composer.lock -c satis.json
```

### `build` — generate the repository

Reads `satis.json`, resolves the tree, downloads/creates archives, and writes the
static repository. Runs the audit afterwards unless `--no-audit` is given.

| Option | Description |
| --- | --- |
| `-c`, `--config <path>` | Path to `satis.json` (default `satis.json`). |
| `--output-dir <dir>` | Output directory (default `output`). |
| `--include-dev` | Include dev dependencies. |
| `--no-audit` | Skip the audit step. |
| `--no-audit-cache` | Audit every package every run instead of skipping versions recorded in `.satiate-cache` — use this when gating CI with `--fail-on`. |
| `--fail-on <severity>` | Exit non-zero if the audit finds an issue at or above `info`, `warning`, or `critical`. |

The archive format is taken from `archive.format` in `satis.json` (`zip` — the
default — or `tar`, `tar.gz`, `tar.bz2`).

### `audit` — scan package code for suspicious patterns

Scans for supply-chain compromise signatures:

- **Code execution / obfuscation** — `eval()`, `create_function()`, string-argument
  `assert()`, encoded payload execution, and **multi-stage decoder chains**
  (`eval(gzinflate(base64_decode(…)))`).
- **Request input → dangerous sink** (Critical) — a request superglobal
  (`$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_FILES`, `getallheaders()`,
  `php://input`) flowing directly into `eval`/`system`/`include`/`unserialize`/
  `extract`/a callable — the canonical backdoor shape.
- **Process execution** — `system`, `passthru`, `pcntl_exec`, ….
- **Native code / runtime tampering** — `FFI::cdef`/`load`/`scope`, `dl()`, and
  `ini_set()` of security-relevant settings (`disable_functions`,
  `allow_url_include`, `auto_prepend_file`, …) — `disable_functions`/sandbox
  bypass surface (Warning).
- **Network egress & exfiltration** — outbound-connection functions (`curl_exec`,
  `fsockopen`, `mail`, …) at Info; literals naming known C2 / exfiltration
  infrastructure (Discord/Telegram webhooks, pastebin, `.onion`, …) at Critical;
  and sensitive-path reconnaissance (`/etc/passwd`, `/proc/self/environ`, …) at
  Warning.
- **Stream-wrapper file functions** — `file_get_contents`, `copy`, `fopen`, …
  (Info for the capability, Critical when the literal argument is a remote or
  `phar://`/`data://` URL).
- **composer.json install surface** — shell commands or inline `@php -r` wired to
  auto-run hooks (`post-install-cmd`, `post-autoload-dump`, …) at Critical, the
  `composer-plugin` type at Warning, and `autoload.files` at Info.
- **PSR-1 mixed content** (Info) — a file that both declares symbols and runs a
  top-level side effect, which can signal code injected into a pure class file.
- **Sudden capability change across versions** — `build` records each version's
  capability fingerprint in `.satiate-cache` and reports when a newly-mirrored
  version gains a capability (exec, eval, install hook, …) the previous version
  lacked. Advisory only — printed for review, never gates the build.

The path may be a plain source tree **or a built mirror**: distribution archives
(`zip`, `tar`, `tar.gz`, `tar.bz2`) are extracted and their PHP (and the package's
`composer.json`) is scanned, with findings located as `<archive>/<internal/path.php>`.

| Option | Description |
| --- | --- |
| `--path <dir>` | Directory to audit (required). |
| `--cache-path <dir>` | Directory holding `audited-files.json` so unchanged files are skipped between runs. |
| `--min-severity <severity>` | Only list findings at or above `info` (default), `warning`, or `critical`. Findings below the threshold are still counted in the summary. |
| `--fail-on <severity>` | Exit non-zero if any finding reaches the given severity (independent of `--min-severity`). |
| `-c`, `--config <path>` | Path to `satis.json` (default `satis.json`). |

A mirror of real libraries trips many benign matches, so the audit ends with a
per-severity breakdown, e.g. `739 issue(s) found. (3 critical, 80 warning, 656 info)`.

### `lock` — derive minimum constraints from `composer.lock`

| Option | Description |
| --- | --- |
| `--lock <path>` | Path to `composer.lock` (default `composer.lock`). |
| `-c`, `--config <path>` | Path to the `satis.json` to update (default `satis.json`). |
| `--dry-run` | Report only; do not modify `satis.json`. |

## Copyright

This package is licenced under [`AGPL-3.0-or-later`](https://www.gnu.org/licenses/agpl-3.0).

```
Satiate - A simple and robust static Composer repository generator.
Copyright (C) 2026  USAMI Kenta <tadsan@zonu.me>

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
```
