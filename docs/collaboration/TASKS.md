# Task board

Status values: `todo` · `in progress` · `in review` · `blocked` · `done`.
Owner: `PO` · `Lead` · `Manager` · `–` (free).
Task IDs match the work packages in `../PLAN.md` §14. Maintained by the Manager; claim a task by editing its row (see WORKFLOW.md §3).

## Work split (maintained by the Manager)

**Lead** – decides and answers all questions (technical + product), leads the implementation, assigns coding sub-tasks to the Manager.
**Manager** – keeps this split and the board current, codes **together with the Lead** on assigned sub-tasks, reviews the Lead's PRs critically. Decides nothing.
**Lead's operator** (the person running the Lead session) – answers when the Lead asks, and does what only a human can do (list below).
**PO** – merges PRs, can veto. Gets no questions.

### Needs the Lead's operator (only a human can answer or do it)

| Item | Why a human | Task / decision |
|------|-------------|-----------------|
| Email Revolut about the open-source module and name | Sends mail from a real account | P0-10 |
| Pick the domain name (never changes later, passkeys bind to it; stays out of the repo) | Owns/buys the domain | B1, D-010 |
| Provide a server that meets the Lead's minimum hardware | Owns the machine/contract | D-010 |
| Create a Revolut Business **sandbox** account and keep its keys private | Account sign-up | D-016, P3-4b |
| Run the device tests on a real iPhone and Android phone | Owns the phones | P0-22 (spikes B5, B14) |
| Supply a few anonymised real bank exports, if the synthetic generator isn't enough | Has real exports | P0-4, P0-21 |
| Product choices the Lead can't settle alone (name, v1 scope, when public, receipt-pack look) | Taste/priorities | D-000, D-011, D-014, D-018 |
| Give the go for Phase 1 coding | Final call | P0-23 → P1-1 |

### What the Manager can take over (always together with / assigned by the Lead)

| Item | Task |
|------|------|
| Merge the Lead's accepted review points into PLAN.md (Lead decides content) | P0-1c |
| Receipt-pack layout draft (Lead signs off, D-018) | P0-5 |
| Rebase/housekeeping of Manager PRs | P0-14 |
| History & content scan before going public (Lead decides D-014) | P0-8 |
| Coding sub-tasks the Lead marks in the Phase 1 breakdown | P0-25 → P1-* |
| Critical reviews of all Lead PRs | WORKFLOW §10 |

## Phase 0 – Planning & setup (current)

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-1a | Review PLAN.md v1 (technical) → PLAN-REVIEW.md | Lead | done | – | PR #2, merged 2026-10-29 |
| P0-1b | Review PLAN.md v1 (product) → PLAN-REVIEW.md | Lead | todo | – | Scope, priorities (asks operator if needed) |
| P0-1c | Merge accepted feedback into PLAN.md (v2) | Manager (for the Lead) | todo | P0-1a, P0-1b | Lead decides what is accepted |
| P0-2a | Technical decisions D-001…D-010, D-012, D-016, D-017, D-019 (STT engine) | Lead | done | – | PR #2. All blocking decisions decided; D-005, D-006, D-007 (files), D-019 engine stay proposed until their phase |
| P0-2b | Product decisions D-000, D-011, D-014, D-018 (receipt pack layout) | Lead | todo | – | D-015, D-019 decided by PO |
| P0-3 | Repo basics: README, LICENSE, AGENTS.md, workflow, templates | Manager | done | – | 2026-10-29 |
| P0-4 | Synthetic/anonymised fixtures: ~30 receipt photos (EXIF stripped), 1 anonymised Revolut export, 2–3 other bank exports | Lead + Manager | todo | P0-21 | Lead (with operator) supplies, Manager checks anonymisation |
| P0-5 | UI sketches: capture, matching, **receipt pack PDF layout**, list, detail, settings | Manager | todo | P0-1c | `docs/design/`, Lead signs off |
| P0-6 | Roles set: Lead + Manager (both Claude) | PO | done | – | 2026-10-29 |
| P0-7 | Public-repo hardening: SECURITY.md, rules, `.gitignore` | Manager | done | – | 2026-10-29 |
| P0-8 | Full history & content scan before going public | Manager | todo | before D-014 | gitleaks + manual review |
| P0-9 | Install required skills (docs/SKILLS.md block 1) on own computer | Lead | done | – | Manager + Lead: done 2026-10-29 |

### Phase 0 – added 2026-10-29

| ID | Task | Owner | Status | Depends on | Notes |
|----|------|-------|--------|------------|-------|
| P0-10 | Ask Revolut (business-partners@revolut.com) whether an open-source module for Revolut Business users and the descriptive name are fine | Lead | todo | – | Blocks P3-4b, not Phase 1. Record only the generic outcome here |
| P0-11 | Decide license policy D-022 | Lead | todo | – | Manager proposal in DECISIONS.md |
| P0-12 | Answer Q-11: `BankConnector` interface + module install mechanism; finalise D-010 yourself (PO leaves Q-8 to the Lead) | Lead | in progress | PR #1 merged | D-010 finalised in PR #2 (one minimum requirement, no Docker); `BankConnector`/module part open |
| P0-13 | Dependency license list `docs/DEPENDENCIES.md` + alternative to Ghostscript/ocrmypdf (D-005, D-022) | Lead | todo | P0-11 | |
| P0-14 | Rebase PR #1 after PR #2 is merged; fill Q-8 row | Lead | done | PR #2 merged | Done by the Lead on PO request: renumbered D-020→D-021, D-021→D-022, Q-9→Q-11, merged main; Q-8 row filled in PR #2 |
| P0-15 | Fix the must-fix points of the Manager's critical review on PR #2 (downloads for the offline worker, DNS-01, SQLite backups) and answer all points | Lead | in progress | – | Downloads + DNS-01 settled in PR #2 (D-010); SQLite moot (D-003 = MariaDB/MySQL). Open: DB backup method, D-016 → module wording (D-021), B12 benchmark criteria, D-002 rule |
| P0-16 | **Critical review of PR #1** (D-021, D-022, P3 split, collaboration rules) – technical feasibility | Lead | todo | – | WORKFLOW §10 |
| P0-17 | Architecture overview `docs/architecture/OVERVIEW.md`: containers, queues, data flow, module mechanism, where each decision lives | Lead | todo | P0-15 | Manager reviews |
| P0-18 | Concrete data model: list of migrations/tables for P1–P4 incl. B7/B8/B16/B17 and generic transaction fields (D-021) | Lead | todo | PR #1 merged | Replaces PLAN §6 tables in P0-1c |
| P0-19 | Threat model `docs/security/THREAT-MODEL.md` (STRIDE) for pairing, upload, file serving, bank modules, receipt-pack links | Lead | todo | – | Manager reviews against PLAN §11 |
| P0-20 | CI & tooling spec: GitHub Actions jobs (lint, PHPStan 8 + Larastan, Pest, gitleaks, license check, compose network test) | Lead | todo | P0-13 | Implemented in P1-1 |
| P0-21 | Synthetic fixture generator spec: fake receipts (images) and fake bank CSVs of several formats, no real data | Lead | todo | – | Reduces P0-4 for the PO |
| P0-22 | Device test plan for the spikes B5 (camera method) and B14 (pairing inside installed PWA): devices, steps, pass criteria | Lead | todo | – | PO runs it on real phones |
| P0-23 | Break P1-1…P1-8 into concrete sub-tasks with acceptance tests, order and rough size | Lead | todo | P0-15 | Manager reviews, then Phase 1 can start |
| P0-24 | Critical review of the Manager's PLAN v2 PR (P0-1c) and receipt-pack layout (P0-5) | Lead | todo | P0-1c, P0-5 | |
| P0-25 | In P0-23: mark which Phase 1 sub-tasks the Manager codes **together with the Lead** (Lead assigns, Lead reviews) | Lead | todo | P0-23 | Manager never codes alone |

## Phase 1 – Foundation (v0.1) – not started

| ID | Task | Owner | Status | Depends on |
|----|------|-------|--------|------------|
| P1-1 | Project skeleton, config, `.env.example`, Docker Compose, CI, gitleaks | Lead | todo | P0-23 + PO go |
| P1-2 | Migrations: identity & access tables | Lead | todo | P1-1 |
| P1-3 | Layout, CSS base, i18n, error pages | Lead | todo | P1-1 |
| P1-4 | Setup CLI, invitations, pairing (link/QR), passkeys | Lead | todo | P1-2 |
| P1-5 | Device token rotation, device list, revoke, step-up | Lead | todo | P1-4 |
| P1-6 | Org scope middleware, roles/policies, permission-matrix tests | Lead | todo | P1-2 |
| P1-7 | Security headers, CSRF, rate limiting, health endpoint | Lead | todo | P1-1 |
| P1-8 | README install, HTTPS docs, backup docs v1 | Lead | todo | P1-1 |

Phases 2–8: see PLAN.md §14 (reordered in v1.1: 2 capture with time/location → 3 Revolut & matching → 4 receipt pack = core flow done → 5 OCR → 6 search → 7 tax advisor access → 8 release). The Manager adds rows when the previous phase is in review.
