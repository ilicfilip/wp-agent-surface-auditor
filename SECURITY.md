# Security Policy

## Reporting a vulnerability

If you believe you have found a security issue in Agent Surface Auditor,
please report it privately. **Do not open a public issue for security
reports.**

- Use GitHub's [private vulnerability reporting](https://github.com/ilicfilip/wp-agent-surface-auditor/security/advisories/new)
  ("Report a vulnerability" under the repository's **Security** tab), or
- email the maintainer at the address on the
  [author profile](https://github.com/ilicfilip).

Please include the plugin version, WordPress and PHP versions, and enough
detail to reproduce. You will get an acknowledgement as soon as possible, and
a fix or mitigation plan once the report is triaged.

## What this plugin does — and does not — do

Agent Surface Auditor is a **read-only auditor**. Understanding its design
boundaries is part of assessing its security:

- It **never** invokes an ability's execute or permission callback, and never
  blocks, modifies, or proxies a real ability call.
- The only thing it writes is one short-lived cache transient
  (`asa_last_report`).
- It registers **no** abilities and exposes **nothing** over MCP — it adds no
  new agent-reachable surface of its own.
- Every screen and REST route requires the `manage_options` capability
  (filterable via `asa_capability`).

A defect that breaks any of these guarantees — for example, a path that causes
the auditor to *execute* an audited ability, or that exposes report data to a
user without `manage_options` — is in scope and worth reporting.

## Scope of the analysis (not vulnerabilities)

The following are known, documented limitations rather than security bugs:

- **Static analysis is heuristic.** Findings below `high` confidence are
  smells, not proofs; the report says "no issues detected", never "safe".
- **Client-side abilities are not covered.** WordPress 7.0 abilities registered
  in the browser via `registerAbility()` are outside the PHP registry this
  plugin reads; the report discloses this in its `coverage` note.

## Supported versions

Security fixes target the latest released version. Please upgrade before
reporting.
