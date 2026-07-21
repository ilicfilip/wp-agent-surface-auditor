=== Agent Surface Auditor ===
Contributors: ilicfilip
Tags: ai, agents, mcp, abilities, security
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only audit of the WordPress Abilities you expose to AI agents. Inventories every Ability, resolves agent exposure, and flags risky combinations.

== Description ==

WordPress 6.9 shipped the Abilities API; the MCP Adapter exposes those
Abilities to AI agents (Claude, Cursor, ChatGPT, and others). An agent
authenticating with an application password executes Abilities **as that
WordPress user** — if the user can delete posts, the agent can too. The only
access control is each Ability's permission callback; annotations such as
"read-only" are self-reported hints. Core ships no tooling that answers *"what
am I actually handing to agents, and is it gated?"*

Agent Surface Auditor answers it. It is strictly **read-only**: it never
executes an Ability, never blocks or modifies anything, and the only thing it
ever writes is a short-lived cache of its own report.

**What it does**

* Inventories every registered Ability with its full descriptor.
* Resolves exposure across both agent-reachable channels: the core
  `wp-abilities/v1 .../run` REST endpoint (`meta.show_in_rest`) and MCP (the
  adapter's default server via `meta.mcp.public`, plus any custom servers'
  explicit ability lists).
* Evaluates a catalog of checks — missing, unconditional-allow, and
  authentication-only permission gates; agent-reachable write/destructive
  Abilities; annotation mismatches ("claims read-only, appears to write");
  loose input schemas; broad capabilities guarding destructive operations;
  and more.
* Scores each Ability and presents a Tools -> Agent Surface report, plus a
  JSON export for CI or diffing.

**Honest about limits.** Static analysis detects *smells* — it cannot prove
safety. The report says "no issues detected", never "safe", and every finding
carries a confidence tier (read-from-flags, source-heuristic, or weak signal).

The MCP Adapter is optional. Without it, the report shows *intended* MCP
exposure from the meta flags and clearly labels it as not-yet-live.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/agent-surface-auditor`, or install
   it through the Plugins screen.
2. Activate it. It requires WordPress 6.9 or later (the Abilities API in core);
   on an unsupported version it declines to activate and shows a notice.
3. Open **Tools -> Agent Surface**.

== Frequently Asked Questions ==

= Does this change or block anything? =

No. It is read-only. It never invokes an Ability's execute or permission
callback, never registers Abilities of its own, and exposes nothing over MCP.
The only database write is a five-minute cache of its own report.

= Do I need the MCP Adapter installed? =

No. The plugin is useful on any WordPress 6.9+ site, because the core REST
run endpoint is an agent-reachable channel on its own. With the MCP Adapter
active (v0.5.0+), the report additionally reflects live MCP server exposure.

= Who can see the report? =

Only users with the `manage_options` capability. The admin page and every
REST route are gated (filter: `asa_capability`).

== Screenshots ==

1. The audit dashboard: risk posture, severity counts, and the abilities table.
2. An expanded finding with its severity, confidence, and remediation.
3. The MCP servers view.

== Changelog ==

= 0.1.0 =
* Initial release: ability inventory, dual-channel exposure resolution,
  ten-rule check catalog with confidence tiers, risk scoring, React dashboard,
  and JSON export.
