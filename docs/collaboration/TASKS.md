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
| P0-2a | Technical decisions D-001…D-010, D-012, D-016, D-017 | Lead | todo | – | Revolut + phone→server are Lead's |
| P0-2b | Product decisions D-000, D-011, D-014, D-018 (receipt pack layout) | PO | todo | – | D-013, D-015 decided |
| P0-3 | Repo basics: README, LICENSE, AGENTS.md, workflow, templates | Manager | done | – | 2026-10-29 |
| P0-4 | Synthetic/anonymised fixtures: ~30 receipt photos (EXIF stripped), 1 anonymised Revolut export, 2–3 other bank exports | PO + Manager | todo | – | Manager checks anonymisation before commit |
| P0-5 | UI sketches: capture, matching, **receipt pack PDF layout**, list, detail, settings | Manager | todo | P0-1c | `docs/design/`, PO signs off |
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

Phases 2–8: see PLAN.md §14 (reordered in v1.1: 2 capture with time/location → 3 Revolut & matching → 4 receipt pack = core flow done → 5 OCR → 6 search → 7 tax advisor access → 8 release). The Manager adds rows when the previous phase is in review.
