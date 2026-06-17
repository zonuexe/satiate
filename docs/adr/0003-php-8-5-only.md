# ADR-0003: Follow PHP's Official Support Timeline

Satiate requires a PHP version that is currently receiving active or security support per [php.net/supported-versions.php](https://www.php.net/supported-versions.php). When the required version reaches end of life, satiate bumps its minimum requirement to the next oldest version still supported.

Every file uses `declare(strict_types=1)`, and the codebase freely uses readonly classes, enums, asymmetric visibility, property hooks, and any other PHP 8.x feature available at the current minimum level.

## Considered Options

- **A (chosen): Follow PHP's official support timeline** — the minimum PHP version is never a guessing game; it tracks a well-known external standard. Users on unsupported PHP versions cannot use satiate, which is acceptable for a tool targeting developers who manage modern Composer repositories.
- **B: Static floor at PHP 8.5** — simpler but would require manual revision when 8.5 reaches EOL. Downstream users would have to track the decision separately.
- **C: Support PHP 8.1+** — would require polyfills, shims, and avoidance of modern patterns, accumulating technical debt from day one.

## Policy

When the current minimum PHP version reaches end of life (both active and security support ended per php.net), the `composer.json` `require` constraint is updated to the next supported version, and the codebase is free to adopt features from the new minimum. This is a mechanical, non-controversial change — no debate, no grace period.
