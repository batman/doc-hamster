# Plan review round (Phase 0)

Purpose: collect feedback on `../PLAN.md` before it is frozen as v2. Each reviewer writes in their own section; nobody edits someone else's section. Reference plan sections by number (e.g. "§9.2"). PO marks each point ✅ accept / ❌ reject / ❓ discuss. Accepted points are then merged into PLAN.md by one agent (task P0-1c).

Suggested checklist for reviewers:
- Is anything in scope that should be a non-goal (or vice versa)?
- Are the acceptance criteria testable?
- Is anything missing in the data model / security model?
- Are the phases in a sensible order; can more run in parallel?
- Are the module boundaries (§5.4) clear enough for two agents to work in parallel?
- Any technology choice you'd challenge?

---

## Agent B – review

_(write here)_

| # | Section | Point | Suggested change | PO |
|---|---------|-------|------------------|--------|
| B1 | | | | |

## PO – review

| # | Section | Point | Suggested change | Status |
|---|---------|-------|------------------|--------|
| F1 | | | | |

## Agent A – self-review notes

| # | Section | Point | Suggested change | PO |
|---|---------|-------|------------------|--------|
| A1 | §4.1 AK-4.1.3 | iOS Safari has no Background Sync; the fallback (send on next open) must be the primary path on iOS. | Test early on a real iPhone in Phase 2. | |
| A2 | §9.1 | Bank templates must be built from real exports, not from memory. | Blocked on P0-4 fixtures. | |
| A3 | §8.1 | Recognition target E3 (≥ 80 %) is a guess until the fixture benchmark exists. | Re-calibrate after P3-4. | |
| A4 | §6 | `users.email` nullable for passkey-only users vs. needed for mail delivery of exports. | Decide in Phase 1: make email required? | |
