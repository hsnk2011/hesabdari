# Smart Audit Center Design (2026-08-16)

## Decisions
- Scope: operational auditor for carpet trading (financial, inventory, entity, system, operations)
- Remediation: conservative — `safe_auto` | `guided` | `navigate_only`
- Merge inventory-audit and balance-audit into Smart Audit Center
- Persist full run history + compare to previous run (no cron)

## Architecture
- `AuditEngine` runs `RuleCatalog`, persists `audit_runs` / `audit_run_issues`, filters ignores by fingerprint, compares runs
- `RemediationService` applies only `safe_auto` (and guided handlers with preview)
- UI center: results, history, inventory link wizard, balance analysis
- Dashboard deep-links into the center

## Issue contract
`rule_id`, `type` (alias of rule_id for UI compat), `severity`, `category`, `title`, `message`, `details`, `reference_table`, `reference_id`, `fingerprint`, `remediation_class`, `remediation_action` / `action_needed`, `meta`, `nav`

## Fingerprint
`sha1(rule_id|reference_table|reference_id|stable_meta)`

## Acceptance
See implementation plan: persist+compare, no false Fix buttons, real drill-down, entity-scoped orphans, invoice math with discount/tax awareness where applicable.
