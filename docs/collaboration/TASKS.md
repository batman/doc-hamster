# Task board

Status values: `todo` · `in progress` · `in review` · `blocked` · `done`.
Owner: `PO` · `Lead` · `Manager` · `–` (free).
Task IDs match the work packages in `../PLAN.md` §14. Maintained by the Manager; claim a task by editing its row (see WORKFLOW.md §3).

## Phase 0 – Planning & setup (current)

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-1a | Review PLAN.md v1 (technical) → PLAN-REVIEW.md | Lead | todo | – | Start with LEAD-KICKOFF.md |
| P0-1b | Review PLAN.md v1 (product) → PLAN-REVIEW.md | PO | todo | – | Scope, priorities |
| P0-1c | Merge accepted feedback into PLAN.md (v2) | Manager | todo | P0-1a, P0-1b | |
| P0-2a | Technical decisions D-001…D-010, D-012, D-015 (server side) | Lead | todo | – | |
| P0-2b | Product decisions D-000, D-011, D-014, D-015 (product side) | PO | todo | – | |
| P0-3 | Repo basics: README, LICENSE, AGENTS.md, workflow, templates | Manager | done | – | 2026-10-29 |
| P0-4 | Synthetic/anonymised fixtures: ~30 receipts/scans, 3–5 bank exports | PO + Manager | todo | – | Manager checks anonymisation before commit |
| P0-5 | UI sketches of the main screens | – | todo | P0-1c | `docs/design/` |
| P0-6 | Roles set: Lead + Manager (both Claude) | PO | done | – | 2026-10-29 |
| P0-7 | Public-repo hardening: SECURITY.md, rules, `.gitignore` | Manager | done | – | 2026-10-29 |
| P0-8 | Full history & content scan before going public | Manager | todo | before D-014 | gitleaks + manual review |

## Phase 1 – Foundation (v0.1) – not started

| ID | Task | Owner | Status | Depends on |
|----|------|-------|--------|------------|
| P1-1 | Project skeleton, config, `.env.example`, Docker Compose, CI, gitleaks | – | todo | P0-2a |
| P1-2 | Migrations: identity & access tables | – | todo | P1-1 |
| P1-3 | Layout, CSS base, i18n, error pages | – | todo | P1-1 |
| P1-4 | Setup CLI, invitations, pairing (link/QR), passkeys | – | todo | P1-2 |
| P1-5 | Device token rotation, device list, revoke, step-up | – | todo | P1-4 |
| P1-6 | Org scope middleware, roles/policies, permission-matrix tests | – | todo | P1-2 |
| P1-7 | Security headers, CSRF, rate limiting, health endpoint | – | todo | P1-1 |
| P1-8 | README install, HTTPS docs, backup docs v1 | – | todo | P1-1 |

Phases 2–8: see PLAN.md §14. The Manager adds rows when the previous phase is in review.
