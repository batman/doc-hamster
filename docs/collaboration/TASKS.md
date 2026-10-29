# Task board

Status values: `todo` · `in progress` · `in review` · `blocked` · `done`.
Owner: `PO` · `Agent A` (Claude Code) · `Agent B` · `–` (free).
Task IDs match the work packages in `../PLAN.md` §14. Claim a task by editing its row (see WORKFLOW.md §3).

## Phase 0 – Planning & setup (current)

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-1a | Review PLAN.md v1 → feedback in PLAN-REVIEW.md | Agent B | todo | – | Fresh eyes first |
| P0-1b | Review PLAN.md v1 → feedback in PLAN-REVIEW.md | PO | todo | – | Product view: scope, priorities |
| P0-1c | Merge accepted feedback into PLAN.md (v2) | Agent A | todo | P0-1a, P0-1b | One editor at a time |
| P0-2 | Decide D-001…D-010 | PO | todo | – | Agents add recommendations (`proposed`) |
| P0-3 | Repo basics: README, LICENSE, AGENTS.md, workflow, templates | Agent A | done | – | 2026-10-29 |
| P0-4 | Collect anonymised fixtures: ~30 receipts, 3–5 bank exports | PO | todo | – | Needed for P3-4, P5-2 |
| P0-5 | UI sketches of the 6 main screens | – | todo | P0-1c | `docs/design/` |
| P0-6 | Fill in which tool Agent B is; adjust AGENTS.md names/prefix | PO | todo | – | |

## Phase 1 – Foundation (v0.1) – not started

| ID | Task | Owner | Status | Depends on |
|----|------|-------|--------|------------|
| P1-1 | Project skeleton, config, `.env.example`, Docker Compose, CI | – | todo | P0-2 |
| P1-2 | Migrations: identity & access tables | – | todo | P1-1 |
| P1-3 | Layout, CSS base, i18n, error pages | – | todo | P1-1 |
| P1-4 | Setup CLI, invitations, pairing (link/QR), passkeys | – | todo | P1-2 |
| P1-5 | Device token rotation, device list, revoke, step-up | – | todo | P1-4 |
| P1-6 | Org scope middleware, roles/policies, permission-matrix tests | – | todo | P1-2 |
| P1-7 | Security headers, CSRF, rate limiting, health endpoint | – | todo | P1-1 |
| P1-8 | README install, HTTPS (Caddy) docs, backup docs v1 | – | todo | P1-1 |

Phases 2–8: see PLAN.md §14. Rows get added here when the previous phase is in review.
